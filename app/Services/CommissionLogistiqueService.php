<?php

namespace App\Services;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Parametre;
use App\Models\TransfertLogistique;
use App\Models\VersementCommissionLogistique;
use App\Notifications\CommissionGenereeNotification;
use App\Notifications\CommissionPayeeNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CommissionLogistiqueService
{
    /**
     * Créer ou recalculer la commission d'un transfert en transit (chargement
     * validé), réceptionné ou clôturé.
     *
     * @throws InvalidArgumentException si le transfert n'est pas éligible
     */
    public static function genererPourTransfert(
        TransfertLogistique $transfert,
        string $baseCalcul,
        float $valeurBase,
        ?int $quantiteReference = null
    ): CommissionLogistique {
        // TRANSIT : déclencheur CHARGEMENT_VALIDE (cf. genererDepuisChargement()) — le
        // transfert vient de quitter CHARGEMENT, avant que la quantité reçue existe.
        if (! $transfert->isTransit() && ! $transfert->isReception() && ! $transfert->isCloture()) {
            throw new InvalidArgumentException(
                'La commission ne peut être générée que sur un transfert en transit, réceptionné ou clôturé.'
            );
        }

        $existingCommission = $transfert->commission()->first();
        if ($existingCommission && (float) $existingCommission->montant_verse > 0) {
            throw new InvalidArgumentException(
                'Impossible de recalculer : des versements ont déjà été enregistrés sur cette commission.'
            );
        }

        $transfert->loadMissing([
            'vehicule.equipe.membres.livreur',
            'vehicule.proprietaire',
        ]);

        $montantTotal = self::calculerMontant($baseCalcul, $valeurBase, $quantiteReference);

        $earnedAt = $transfert->date_arrivee_reelle
            ? Carbon::instance($transfert->date_arrivee_reelle)
            : now();

        return DB::transaction(function () use (
            $transfert, $baseCalcul, $valeurBase, $quantiteReference, $montantTotal, $earnedAt
        ) {
            /** @var CommissionLogistique $commission */
            $commission = CommissionLogistique::updateOrCreate(
                ['transfert_logistique_id' => $transfert->id],
                [
                    'organization_id' => $transfert->organization_id,
                    'vehicule_id' => $transfert->vehicule_id,
                    'base_calcul' => $baseCalcul,
                    'valeur_base' => $valeurBase,
                    'quantite_reference' => $quantiteReference,
                    'montant_total' => $montantTotal,
                    'montant_verse' => 0,
                    // Toujours CREEE : ne devient IMPAYE(E) qu'à la validation de la
                    // période de paiement qui la couvre — cf.
                    // CommissionAdjustmentService::activerCommissionsCreees().
                    'statut' => StatutCommission::CREEE,
                ]
            );

            if ($commission->wasRecentlyCreated || $commission->isCreee() || $commission->isImpaye()) {
                $commission->parts()->delete();
                self::creerParts($commission, $transfert, $montantTotal, $earnedAt);
            }

            // Une période déjà "Calculée" ne doit pas rester silencieusement obsolète.
            app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($transfert->organization_id, $earnedAt);

            return $commission->load('parts');
        });
    }

    /**
     * Génération automatique après validation admin : montant configurable (Parametre::
     * getMontantDefautCommissionLogistiquePack(), 200 FG par défaut) × packs reçus.
     * Idempotente : si une commission existe déjà, ne rien faire.
     */
    public static function genererAutomatique(TransfertLogistique $transfert): CommissionLogistique
    {
        $existing = $transfert->commission()->first();
        if ($existing) {
            return $existing->load('parts');
        }

        $transfert->loadMissing('lignes');
        $quantiteRecue = (int) $transfert->lignes->sum('quantite_recue');

        return self::genererPourTransfert(
            $transfert,
            BaseCalculLogistique::PAR_PACK->value,
            Parametre::getMontantDefautCommissionLogistiquePack($transfert->organization_id),
            $quantiteRecue > 0 ? $quantiteRecue : 0,
        );
    }

    /**
     * Génération automatique au chargement validé (départ, CHARGEMENT → TRANSIT) :
     * montant configurable (Parametre::getMontantDefautCommissionLogistiquePack(), 200 FG par
     * défaut) × packs **chargés** — la quantité reçue n'existe pas encore à ce stade.
     * Idempotente : si une commission existe déjà, ne rien faire. Le montant est figé
     * à cet instant ; un écart constaté à la réception (quantite_recue) ne déclenche
     * jamais de recalcul rétroactif de cette commission.
     */
    public static function genererDepuisChargement(TransfertLogistique $transfert): CommissionLogistique
    {
        $existing = $transfert->commission()->first();
        if ($existing) {
            return $existing->load('parts');
        }

        $transfert->loadMissing('lignes');
        $quantiteChargee = (int) $transfert->lignes->sum('quantite_chargee');

        return self::genererPourTransfert(
            $transfert,
            BaseCalculLogistique::PAR_PACK->value,
            Parametre::getMontantDefautCommissionLogistiquePack($transfert->organization_id),
            $quantiteChargee > 0 ? $quantiteChargee : 0,
        );
    }

    /**
     * Versement legacy (retro-compat, utilisé depuis la page Transfert Show).
     */
    public static function verser(
        CommissionLogistiquePart $part,
        float $montant,
        string $dateVersement,
        string $modePaiement,
        ?string $note = null
    ): VersementCommissionLogistique {
        if ($part->isPaye()) {
            throw new InvalidArgumentException('Cette part est déjà entièrement versée.');
        }

        return DB::transaction(function () use ($part, $montant, $dateVersement, $modePaiement, $note) {
            $versement = VersementCommissionLogistique::create([
                'commission_logistique_part_id' => $part->id,
                'montant' => $montant,
                'date_versement' => $dateVersement,
                'mode_paiement' => $modePaiement,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            $part->recalculStatut();

            self::notifierCommissionPayee($part, $montant, $modePaiement, $note);

            return $versement;
        });
    }

    /**
     * Détermine l'agence responsable du financement de la commission logistique
     * d'un transfert.
     *
     * ⚠️ DÉCISION MÉTIER NON TRANCHÉE : un transfert relie deux sites
     * (site_source_id, site_destination_id) et rien dans les règles actuelles
     * du projet ne désigne lequel des deux doit financer la commission de
     * l'équipe qui a effectué le transfert. Cette méthode isole ce choix en un
     * point unique pour qu'il soit trivial à changer une fois la règle
     * fonctionnelle confirmée — ne pas dupliquer cette décision ailleurs.
     *
     * Choix par défaut retenu ici (à confirmer) : le site source, l'agence de
     * rattachement du véhicule/équipe qui exécute le transfert et à qui
     * l'agence de départ doit reverser la commission de sa propre équipe.
     */
    public static function resolveSiteResponsable(TransfertLogistique $transfert): ?string
    {
        return $transfert->site_source_id;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function calculerMontant(string $baseCalcul, float $valeurBase, ?int $quantite): float
    {
        return match ($baseCalcul) {
            BaseCalculLogistique::FORFAIT->value => $valeurBase,
            BaseCalculLogistique::PAR_PACK->value,
            BaseCalculLogistique::PAR_KM->value => $valeurBase * ($quantite ?? 0),
            default => $valeurBase,
        };
    }

    private static function creerParts(
        CommissionLogistique $commission,
        TransfertLogistique $transfert,
        float $montantTotal,
        Carbon $earnedAt
    ): void {
        $vehicule = $transfert->vehicule;
        if (! $vehicule) {
            return;
        }

        $equipe = $vehicule->equipe;
        if ($equipe) {
            $membres = $equipe->membres()->with('livreur')->get();
            foreach ($membres as $membre) {
                // Barème logistique distinct du barème vente (taux_commission), cf.
                // EquipeLivreur::tauxCommissionLogistiqueEffectif() — repli sur le barème vente
                // tant qu'aucun barème logistique n'a été configuré explicitement.
                $taux = $membre->tauxCommissionLogistiqueEffectif();
                $brut = round($montantTotal * $taux / 100, 2);
                $nomLivreur = $membre->livreur
                    ? $membre->livreur->libelleAffichage()
                    : "Livreur #{$membre->livreur_id}";

                $part = CommissionLogistiquePart::create([
                    'commission_logistique_id' => $commission->id,
                    'type_beneficiaire' => 'livreur',
                    'livreur_id' => $membre->livreur_id,
                    'proprietaire_id' => null,
                    'beneficiaire_nom' => $nomLivreur ?: "Livreur #{$membre->livreur_id}",
                    'taux_commission' => $taux,
                    'montant_brut' => $brut,
                    'frais_supplementaires' => 0,
                    'montant_net' => $brut,
                    'montant_verse' => 0,
                    'statut' => StatutCommission::CREEE,
                    'earned_at' => $earnedAt->toDateString(),
                    'periode' => PeriodeComptableService::codeForLivreur($earnedAt),
                ]);

                self::notifierCommissionGeneree($transfert, $part);
            }
        }
    }

    /**
     * Notifie le livreur bénéficiaire réellement connecté d'une part de
     * commission logistique venant d'être créée — jamais de rethrow vers
     * l'appelant (même garantie que le reste de cette classe).
     */
    private static function notifierCommissionGeneree(TransfertLogistique $transfert, CommissionLogistiquePart $part): void
    {
        try {
            $user = BeneficiaireUserResolver::resolve('livreur', $part->livreur_id);
            $notif = new CommissionGenereeNotification('transfert_logistique', $transfert->id, $transfert->reference, (float) $part->montant_net);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'commissions',
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'commission.generated', 'transfert_id' => $transfert->id],
                ] : null,
            );
        } catch (Throwable $e) {
            Log::error('CommissionGenereeNotification (logistique) : envoi échoué', [
                'transfert_id' => $transfert->id,
                'part_id' => $part->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le bénéficiaire réel (livreur ou proprietaire — cf.
     * type_beneficiaire) d'un versement legacy de commission logistique.
     */
    private static function notifierCommissionPayee(CommissionLogistiquePart $part, float $montant, string $modePaiement, ?string $note): void
    {
        try {
            $beneficiaireId = $part->type_beneficiaire === 'livreur' ? $part->livreur_id : $part->proprietaire_id;
            $user = BeneficiaireUserResolver::resolve($part->type_beneficiaire, $beneficiaireId);
            $notif = new CommissionPayeeNotification($montant, $modePaiement, $note, 'commission_logistique_part', $part->id);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'commissions',
                // Pas d'ID navigable ici (part de commission = ligne comptable interne, aucune
                // route de détail côté PWA) — le type seul suffit (cf. rapport Web Push 7/7).
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'commission.paid'],
                ] : null,
            );
        } catch (Throwable $e) {
            Log::error('CommissionPayeeNotification (versement legacy) : envoi échoué', [
                'part_id' => $part->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
