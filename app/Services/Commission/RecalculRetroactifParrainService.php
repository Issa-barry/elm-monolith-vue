<?php

namespace App\Services\Commission;

use App\Enums\AuditEvent;
use App\Enums\StatutCommission;
use App\Enums\TypePeriodePaiement;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionPartAdjustment;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\PeriodeCalculatorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Droits du parrain antidatés (PARRAIN-023, décisions du 24/10/2026) : la nouvelle répartition du
 * partage prend effet à une date passée, et la commission Livreur des commandes du véhicule gagnées
 * depuis cette date est recalculée — part du parrain incluse, parts des livreurs réduites d'autant,
 * total inchangé — avec l'équipe et la répartition ACTUELLES (le partage antidaté couvre toute la
 * période). Option B : seules les commissions non payées sont recalculées, ajustements manuels et
 * validations comprises (tracés dans le journal d'audit) ; une période validée est rouverte
 * (écritures contrepassées puis revalidation) par le mécanisme commun, en mode strict : si une
 * période ne peut pas être rouverte ou si ses fiches restent périmées, toute l'opération est
 * annulée (jamais une fiche validée sur d'anciens montants).
 *
 * Exclues : commission payée ou partiellement versée, part sur une fiche déjà payée (figée), date
 * dans une période Livreurs ou Parrains clôturée. Idempotent : une commande dont la commission
 * recalculée serait identique n'est ni recréée ni rouverte.
 */
class RecalculRetroactifParrainService
{
    /** Types de période dont la clôture interdit le recalcul (dette du livreur et du parrain). */
    private const TYPES_PERIODE = [TypePeriodePaiement::LIVREUR, TypePeriodePaiement::PARRAIN];

    /**
     * Date la plus ancienne autorisée : le lendemain de la dernière période Livreurs ou Parrains
     * clôturée de l'organisation (aucune limite sinon).
     */
    public static function dateMinimale(string $organizationId): ?Carbon
    {
        $fin = PaiementPeriode::where('organization_id', $organizationId)
            ->whereIn('type', array_map(fn (TypePeriodePaiement $t) => $t->value, self::TYPES_PERIODE))
            ->get()
            ->filter(fn (PaiementPeriode $p) => $p->isCloturee())
            ->max('date_fin');

        return $fin ? Carbon::parse($fin)->addDay()->startOfDay() : null;
    }

    /**
     * À appeler dans la transaction qui vient d'antidater la répartition : toute erreur lève une
     * ValidationException et annule l'ensemble (répartition comprise).
     *
     * @return array{recalculees: list<string>, inchangees: list<string>, exclues: list<array{reference: string, motif: string}>}
     *
     * @throws ValidationException
     */
    public static function recalculer(Vehicule $vehicule, Carbon $depuis): array
    {
        $enveloppes = CommissionEnveloppe::query()
            ->where('organization_id', $vehicule->organization_id)
            ->where('source_type', CommandeVente::class)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereDate('earned_at', '>=', $depuis->toDateString())
            ->whereIn('source_id', CommandeVente::where('vehicule_id', $vehicule->id)->select('id'))
            ->with('parts')
            ->orderBy('earned_at')
            ->get();

        $recalculees = [];
        $inchangees = [];
        $exclues = [];
        $dates = collect();

        foreach ($enveloppes as $enveloppe) {
            $commande = CommandeVente::with(['lignes', 'vehicule', 'client'])->find($enveloppe->source_id);
            if (! $commande) {
                continue;
            }

            $motif = self::motifExclusion($enveloppe);
            if ($motif !== null) {
                $exclues[] = ['reference' => $commande->reference, 'motif' => $motif];

                continue;
            }

            $anciennes = self::partsEffectives($enveloppe->parts);
            $remplacees = self::decisionsRemplacees($enveloppe);

            try {
                // Point de sauvegarde : une commission recalculée à l'identique est restaurée telle
                // quelle (aucune version, aucune réouverture de période).
                DB::transaction(function () use ($commande, $anciennes) {
                    CommissionEnveloppeGenerator::regenererEnveloppeEquipe($commande);

                    $nouvelles = self::partsEffectives(self::enveloppeEquipe($commande)?->parts ?? collect());
                    if ($nouvelles === $anciennes) {
                        throw new RepartitionDejaAJour;
                    }
                });
            } catch (RepartitionDejaAJour) {
                $inchangees[] = $commande->reference;

                continue;
            }

            self::tracer($commande, $depuis, $anciennes, $remplacees);
            $dates->push($enveloppe->earned_at);
            $recalculees[] = $commande->reference;
        }

        if ($dates->isNotEmpty()) {
            try {
                // Réouverture des périodes validées (contrepassation), recalcul des fiches puis
                // revalidation automatique — en mode strict : tout échec annule l'opération.
                app(PeriodeCalculatorService::class)->traiterPeriodesPourDates($vehicule->organization_id, $dates, strict: true);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'repartitions' => 'Recalcul annulé, rien n\'a été modifié : une période de paiement concernée ne peut pas être mise à jour ('.$e->getMessage().').',
                ]);
            }
        }

        return ['recalculees' => $recalculees, 'inchangees' => $inchangees, 'exclues' => $exclues];
    }

    private static function enveloppeEquipe(CommandeVente $commande): ?CommissionEnveloppe
    {
        return CommissionEnveloppe::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->with('parts')
            ->first();
    }

    /**
     * Montant réellement dû par bénéficiaire (ajustement manuel compris), pour comparer avant et
     * après recalcul.
     *
     * @param  Collection<int, CommissionEnveloppePart>  $parts
     * @return array<string, string>
     */
    private static function partsEffectives(Collection $parts): array
    {
        return $parts
            ->reject(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::ANNULEE)
            ->mapWithKeys(fn (CommissionEnveloppePart $p) => [
                "{$p->beneficiaire_type}:{$p->beneficiaire_id}" => number_format((float) ($p->montant_actuel ?? $p->montant_net), 2, '.', ''),
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * Décisions humaines effacées par le recalcul (option B) : ajustements manuels et validations.
     *
     * @return list<array<string, mixed>>
     */
    private static function decisionsRemplacees(CommissionEnveloppe $enveloppe): array
    {
        return $enveloppe->parts
            ->filter(fn (CommissionEnveloppePart $p) => $p->montant_actuel !== null || $p->validated_by !== null)
            ->map(fn (CommissionEnveloppePart $p) => [
                'part_id' => $p->id,
                'beneficiaire' => "{$p->beneficiaire_type}:{$p->beneficiaire_id}",
                'montant_theorique' => (float) $p->montant_net,
                'montant_ajuste' => $p->montant_actuel !== null ? (float) $p->montant_actuel : null,
                'validee_par' => $p->validated_by,
                'validee_le' => $p->validated_at?->toIso8601String(),
                'ajustements' => CommissionPartAdjustment::where('commission_part_type', $p->getMorphClass())
                    ->where('commission_part_id', $p->id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (CommissionPartAdjustment $a) => [
                        'ancien_montant' => (float) $a->ancien_montant,
                        'nouveau_montant' => (float) $a->nouveau_montant,
                        'motif' => $a->motif,
                        'commentaire' => $a->commentaire,
                        'par' => $a->created_by,
                        'le' => $a->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Trace d'audit sur la commande : parts avant / après et décisions humaines remplacées.
     *
     * @param  array<string, string>  $anciennes
     * @param  list<array<string, mixed>>  $remplacees
     */
    private static function tracer(CommandeVente $commande, Carbon $depuis, array $anciennes, array $remplacees): void
    {
        $nouvelles = self::partsEffectives(self::enveloppeEquipe($commande)?->parts ?? collect());

        app(AuditLogService::class)->record(
            $commande,
            AuditEvent::AUTO_RECALCULATED,
            auth()->user(),
            ['parts_livreur' => $anciennes],
            ['parts_livreur' => $nouvelles],
            [
                'module' => 'commissions',
                'description' => sprintf(
                    'Commission Livreur recalculée : part du parrain prise rétroactivement depuis le %s (PARRAIN-023)%s.',
                    $depuis->format('d/m/Y'),
                    $remplacees === [] ? '' : ' — '.count($remplacees).' décision(s) remplacée(s) (ajustement manuel ou validation)',
                ),
                'decisions_remplacees' => $remplacees,
            ],
        );
    }

    /** Pourquoi cette commission ne peut pas être recalculée (option B), null si elle le peut. */
    private static function motifExclusion(CommissionEnveloppe $enveloppe): ?string
    {
        $parts = $enveloppe->parts->reject(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::ANNULEE);

        if ($parts->contains(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::PAYE || (float) $p->montant_verse > 0.009)) {
            return 'commission déjà payée';
        }

        $fiches = PaiementFiche::whereHas('lignes', fn ($q) => $q
            ->where('source_type', CommissionEnveloppePart::class)
            ->whereIn('source_id', $parts->pluck('id')))
            ->with('periode')
            ->get();

        if ($fiches->contains(fn (PaiementFiche $f) => $f->periode?->isCloturee())
            || self::dansUnePeriodeCloturee($enveloppe)) {
            return 'période de paiement clôturée';
        }

        if ($fiches->contains(fn (PaiementFiche $f) => $f->estFigee())) {
            return 'fiche de paiement déjà payée';
        }

        return null;
    }

    private static function dansUnePeriodeCloturee(CommissionEnveloppe $enveloppe): bool
    {
        $jour = Carbon::parse($enveloppe->earned_at)->toDateString();

        return PaiementPeriode::where('organization_id', $enveloppe->organization_id)
            ->whereIn('type', array_map(fn (TypePeriodePaiement $t) => $t->value, self::TYPES_PERIODE))
            ->whereDate('date_debut', '<=', $jour)
            ->whereDate('date_fin', '>=', $jour)
            ->get()
            ->contains(fn (PaiementPeriode $p) => $p->isCloturee());
    }

    /**
     * @param  array{recalculees: list<string>, inchangees?: list<string>, exclues: list<array{reference: string, motif: string}>}  $bilan
     */
    public static function resume(array $bilan, Carbon $depuis): string
    {
        $texte = sprintf(
            '%d commande(s) recalculée(s) depuis le %s',
            count($bilan['recalculees']),
            $depuis->format('d/m/Y'),
        );

        if (($bilan['inchangees'] ?? []) !== []) {
            $texte .= sprintf(' ; %d déjà à jour', count($bilan['inchangees']));
        }

        if ($bilan['exclues'] !== []) {
            $texte .= sprintf(
                ' ; %d exclue(s) : %s',
                count($bilan['exclues']),
                Collection::make($bilan['exclues'])
                    ->map(fn (array $e) => "{$e['reference']} ({$e['motif']})")
                    ->implode(', '),
            );
        }

        return $texte.'.';
    }
}
