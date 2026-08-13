<?php

namespace App\Services;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\TransfertLogistique;
use App\Models\VersementCommissionLogistique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommissionLogistiqueService
{
    /**
     * Créer ou recalculer la commission d'un transfert réceptionné/clôturé.
     *
     * @throws InvalidArgumentException si le transfert n'est pas éligible
     */
    public static function genererPourTransfert(
        TransfertLogistique $transfert,
        string $baseCalcul,
        float $valeurBase,
        ?int $quantiteReference = null
    ): CommissionLogistique {
        if (! $transfert->isReception() && ! $transfert->isCloture()) {
            throw new InvalidArgumentException(
                'La commission ne peut être générée que sur un transfert réceptionné ou clôturé.'
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
                    'statut' => StatutCommission::IMPAYE,
                ]
            );

            if ($commission->wasRecentlyCreated || $commission->isImpaye()) {
                $commission->parts()->delete();
                self::creerParts($commission, $transfert, $montantTotal, $earnedAt);
            }

            // Cf. CommissionGenerator::generateForCommandeIfMissing() : une période déjà
            // "Calculée" ne doit pas rester silencieusement obsolète.
            app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($transfert->organization_id, $earnedAt);

            return $commission->load('parts');
        });
    }

    /**
     * Génération automatique après validation admin : 200 FG × packs reçus.
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
            200.0,
            $quantiteRecue > 0 ? $quantiteRecue : 0,
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

                CommissionLogistiquePart::create([
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
                    'statut' => StatutCommission::IMPAYE,
                    'earned_at' => $earnedAt->toDateString(),
                    'periode' => PeriodeComptableService::codeForLivreur($earnedAt),
                ]);
            }
        }
    }
}
