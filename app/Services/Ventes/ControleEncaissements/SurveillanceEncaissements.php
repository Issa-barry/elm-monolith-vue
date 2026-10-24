<?php

namespace App\Services\Ventes\ControleEncaissements;

use App\Enums\AuditEvent;
use App\Enums\StatutAnomalieEncaissement;
use App\Enums\TypeAnomalieEncaissement;
use App\Models\AnomalieEncaissement;
use App\Models\ControleEncaissements;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Contrôle des encaissements (docs/controle-encaissements.md, ADR 0036) : rapproche les constats
 * du détecteur des anomalies enregistrées.
 *
 * - Constat sans anomalie → anomalie « Nouvelle » + alerte (agents et responsables).
 * - Constat d'une anomalie dont l'écart avait disparu → écart réapparu ; si elle était résolue, elle
 *   est rouverte (« Nouvelle », occurrence suivante, nouvelle alerte). Une fausse alerte reste close.
 * - Anomalie du périmètre contrôlé sans constat → « écart disparu » (condition pour la classer
 *   résolue). Rien n'est jamais clos automatiquement.
 * - Relance des anomalies restées « Nouvelle » (critiques et attention) toutes les DELAI_RELANCE_HEURES,
 *   au plus MAX_RELANCES fois, aux responsables.
 *
 * Ne corrige jamais une donnée financière : aucune suppression, aucun remboursement, aucune
 * contrepassation.
 */
class SurveillanceEncaissements
{
    public const DELAI_RELANCE_HEURES = 24;

    public const MAX_RELANCES = 3;

    /** Au-delà, l'écran signale que le contrôle planifié ne tourne plus (cron absent). */
    public const RETARD_PLANIFICATEUR_MINUTES = 150;

    public function __construct(
        private readonly DetecteurAnomaliesEncaissement $detecteur,
        private readonly AlertesAnomaliesEncaissement $alertes,
        private readonly AuditLogService $audit,
    ) {}

    /** Contrôle complet d'une organisation (tâche planifiée, commande, bouton « Analyser maintenant »). */
    public function controlerOrganisation(string $organizationId, string $declencheur, ?User $user = null): ControleEncaissements
    {
        $controle = ControleEncaissements::create([
            'organization_id' => $organizationId,
            'declencheur' => $declencheur,
            'user_id' => $user?->id,
            'debut' => now(),
        ]);

        try {
            $constats = $this->detecteur->pourOrganisation($organizationId);
            $bilan = $this->synchroniser(
                $organizationId,
                $constats,
                AnomalieEncaissement::query()->where('organization_id', $organizationId),
                $declencheur === ControleEncaissements::MANUEL && $user ? 'Contrôle lancé par '.$user->name : 'Contrôle '.$declencheur,
            );
            $bilan['nb_relances'] = $this->relancer($organizationId);

            $controle->update([...$bilan, 'nb_constats' => count($constats), 'fin' => now()]);
        } catch (Throwable $e) {
            $controle->update(['fin' => now(), 'erreur' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        return $controle;
    }

    /**
     * Contrôle immédiat d'une facture, après un encaissement, une suppression ou un remboursement.
     * Les e-mails partent après la réponse : l'opération financière n'attend jamais le SMTP.
     *
     * @param  list<string>  $encaissementsSupprimes
     */
    public function controlerFacture(string $factureId, ?string $cause = null, array $encaissementsSupprimes = [], bool $emailsDifferes = true): void
    {
        $facture = FactureVente::find($factureId);
        if (! $facture) {
            return;
        }

        $constats = $this->detecteur->pourFacture($facture, $encaissementsSupprimes);

        // Périmètre recalculable depuis cette seule facture : son écart, ses doublons et les
        // encaissements sans écriture. Une écriture orpheline ne disparaît qu'au contrôle complet.
        $perimetre = AnomalieEncaissement::query()
            ->where('organization_id', $facture->organization_id)
            ->where('facture_vente_id', $facture->id)
            ->where(fn ($q) => $q->where('cle', 'like', 'ecart:%')
                ->orWhere('cle', 'like', 'doublon:%')
                ->orWhere('cle', 'like', 'compta:encaissement:%'));

        $bilan = $this->synchroniser($facture->organization_id, $constats, $perimetre, $cause);

        if ($bilan['alertees'] !== []) {
            $ids = $bilan['alertees'];
            $envoyer = fn () => $this->alertes->envoyerEnAttente($ids);
            $emailsDifferes ? defer($envoyer) : $envoyer();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $constats
     * @return array{nb_nouvelles: int, nb_disparues: int, alertees: list<string>}
     */
    private function synchroniser(string $organizationId, array $constats, $perimetre, ?string $cause): array
    {
        $nouvelles = 0;
        $alertees = [];
        $cles = [];

        foreach ($constats as $constat) {
            $cles[] = $constat['cle'];
            [$anomalie, $aAlerter] = $this->enregistrerConstat($organizationId, $constat, $cause);
            if ($anomalie && $aAlerter) {
                $nouvelles += $anomalie->occurrence === 1 ? 1 : 0;
                $alertees[] = $anomalie->id;
                $this->alertes->alerterDetection($anomalie);
            }
        }

        $disparues = 0;
        (clone $perimetre)
            ->whereNull('ecart_disparu_le')
            ->when($cles !== [], fn ($q) => $q->whereNotIn('cle', $cles))
            ->get()
            ->each(function (AnomalieEncaissement $a) use (&$disparues, $cause) {
                $a->update(['ecart_disparu_le' => now()]);
                $this->audit->record($a, AuditEvent::ANOMALIE_ECART_DISPARU, null, null, null, array_filter(['cause' => $cause]));
                $disparues++;
            });

        return ['nb_nouvelles' => $nouvelles, 'nb_disparues' => $disparues, 'alertees' => $alertees];
    }

    /**
     * @param  array<string, mixed>  $constat
     * @return array{0: ?AnomalieEncaissement, 1: bool} l'anomalie et s'il faut alerter
     */
    private function enregistrerConstat(string $organizationId, array $constat, ?string $cause): array
    {
        /** @var TypeAnomalieEncaissement $type */
        $type = $constat['type'];
        $valeurs = [
            'type' => $type,
            'gravite' => $type->gravite(),
            'facture_vente_id' => $constat['facture_vente_id'],
            'commande_vente_id' => $constat['commande_vente_id'],
            'site_id' => $constat['site_id'],
            'montant_facture' => $constat['montant_facture'],
            'montant_encaisse_net' => $constat['montant_encaisse_net'],
            'surplus' => $constat['surplus'],
            'agent_ids' => $constat['agent_ids'],
            'details' => $constat['details'],
            'derniere_detection_le' => now(),
        ];

        return DB::transaction(function () use ($organizationId, $constat, $valeurs, $type, $cause) {
            $anomalie = AnomalieEncaissement::query()
                ->where('organization_id', $organizationId)
                ->where('cle', $constat['cle'])
                ->lockForUpdate()
                ->first();

            if (! $anomalie) {
                try {
                    $anomalie = AnomalieEncaissement::create([
                        ...$valeurs,
                        'organization_id' => $organizationId,
                        'cle' => $constat['cle'],
                        'statut' => StatutAnomalieEncaissement::NOUVELLE,
                        'occurrence' => 1,
                        'detectee_le' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Contrôle concurrent : l'autre l'a créée et alertée.
                    return [null, false];
                }
                $this->audit->record($anomalie, AuditEvent::ANOMALIE_DETECTEE, null, null, [
                    'type' => $type->label(),
                    'surplus' => $constat['surplus'],
                ], array_filter(['cause' => $cause]));

                return [$anomalie, true];
            }

            $reapparue = $anomalie->ecart_disparu_le !== null;
            $rouverte = $anomalie->statut === StatutAnomalieEncaissement::RESOLUE;

            $anomalie->fill($valeurs);
            if ($reapparue) {
                $anomalie->ecart_disparu_le = null;
            }
            if ($rouverte) {
                $anomalie->fill([
                    'statut' => StatutAnomalieEncaissement::NOUVELLE,
                    'occurrence' => $anomalie->occurrence + 1,
                    'cloture_par' => null,
                    'cloture_le' => null,
                    'commentaire_cloture' => null,
                    'pris_en_charge_par' => null,
                    'pris_en_charge_le' => null,
                    'nb_relances' => 0,
                    'derniere_relance_le' => null,
                ]);
            }
            $anomalie->save();

            if ($reapparue || $rouverte) {
                $this->audit->record($anomalie, AuditEvent::ANOMALIE_REAPPARUE, null, null, [
                    'rouverte' => $rouverte,
                    'surplus' => $constat['surplus'],
                ], array_filter(['cause' => $cause]));
            }

            return [$anomalie, $rouverte];
        });
    }

    private function relancer(string $organizationId): int
    {
        $relances = 0;

        AnomalieEncaissement::query()
            ->where('organization_id', $organizationId)
            ->where('statut', StatutAnomalieEncaissement::NOUVELLE)
            ->whereIn('gravite', ['critique', 'attention'])
            ->whereNull('ecart_disparu_le')
            ->where('nb_relances', '<', self::MAX_RELANCES)
            ->get()
            ->filter(fn (AnomalieEncaissement $a) => ($a->derniere_relance_le ?? $a->detectee_le)->lte(now()->subHours(self::DELAI_RELANCE_HEURES)))
            ->each(function (AnomalieEncaissement $a) use (&$relances) {
                $a->update(['nb_relances' => $a->nb_relances + 1, 'derniere_relance_le' => now()]);
                $this->alertes->alerterRelance($a);
                $relances++;
            });

        return $relances;
    }

    /** Dernier contrôle planifié réussi de l'organisation (preuve que le cron tourne). */
    public static function dernierControlePlanifie(string $organizationId): ?ControleEncaissements
    {
        return ControleEncaissements::query()
            ->where('organization_id', $organizationId)
            ->where('declencheur', ControleEncaissements::PLANIFICATEUR)
            ->whereNotNull('fin')
            ->whereNull('erreur')
            ->latest('debut')
            ->first();
    }

    /**
     * Point d'entrée des hooks de modèle (encaissement créé / supprimé, remboursement) : contrôle
     * après validation de la transaction, jamais d'exception vers l'opération financière.
     *
     * @param  list<string>  $encaissementsSupprimes
     */
    public static function apresOperation(?string $factureId, ?string $cause, array $encaissementsSupprimes = []): void
    {
        if (! $factureId) {
            return;
        }

        DB::afterCommit(function () use ($factureId, $cause, $encaissementsSupprimes) {
            try {
                app(self::class)->controlerFacture($factureId, $cause, $encaissementsSupprimes);
            } catch (Throwable $e) {
                Log::error('Contrôle des encaissements : contrôle immédiat échoué', ['facture_vente_id' => $factureId, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Toutes les organisations, même sans facture : chacune garde la trace de son contrôle planifié
     * (sinon l'écran la signalerait à tort comme « contrôle automatique absent »).
     *
     * @return Collection<int, string>
     */
    public static function organisationsAControler(): Collection
    {
        return Organization::query()->pluck('id');
    }
}
