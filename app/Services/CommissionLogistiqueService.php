<?php

namespace App\Services;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommissionLogistique;
use App\Enums\StatutPartCommission;
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

        // earned_at = date de réception réelle si disponible, sinon maintenant
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
                    'organization_id'    => $transfert->organization_id,
                    'vehicule_id'        => $transfert->vehicule_id,
                    'base_calcul'        => $baseCalcul,
                    'valeur_base'        => $valeurBase,
                    'quantite_reference' => $quantiteReference,
                    'montant_total'      => $montantTotal,
                    'montant_verse'      => 0,
                    'statut'             => StatutCommissionLogistique::EN_ATTENTE,
                ]
            );

            if ($commission->wasRecentlyCreated || $commission->isEnAttente()) {
                $commission->parts()->delete();
                self::creerParts($commission, $transfert, $montantTotal, $earnedAt);
            }

            return $commission->load('parts');
        });
    }

    /**
     * Versement legacy (retro-compat, utilisé depuis la page Transfert Show).
     * Les nouvelles saisies passent par CommissionPaymentService.
     */
    public static function verser(
        CommissionLogistiquePart $part,
        float $montant,
        string $dateVersement,
        string $modePaiement,
        ?string $note = null
    ): VersementCommissionLogistique {
        if ($part->isVersee()) {
            throw new InvalidArgumentException('Cette part est déjà entièrement versée.');
        }

        return DB::transaction(function () use ($part, $montant, $dateVersement, $modePaiement, $note) {
            $versement = VersementCommissionLogistique::create([
                'commission_logistique_part_id' => $part->id,
                'montant'                       => $montant,
                'date_versement'                => $dateVersement,
                'mode_paiement'                 => $modePaiement,
                'note'                          => $note,
                'created_by'                    => Auth::id(),
            ]);

            $part->recalculStatut();

            return $versement;
        });
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function calculerMontant(string $baseCalcul, float $valeurBase, ?int $quantite): float
    {
        return match ($baseCalcul) {
            BaseCalculLogistique::FORFAIT->value  => $valeurBase,
            BaseCalculLogistique::PAR_PACK->value,
            BaseCalculLogistique::PAR_KM->value   => $valeurBase * ($quantite ?? 0),
            default                               => $valeurBase,
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

        // ── Part propriétaire ─────────────────────────────────────────────────
        if ($vehicule->proprietaire) {
            $taux = (float) ($vehicule->taux_commission_proprietaire ?? 0);
            $brut = round($montantTotal * $taux / 100, 2);
            $unlockAt = CommissionLogistiquePart::calculerUnlockAt('proprietaire', $earnedAt);

            CommissionLogistiquePart::create([
                'commission_logistique_id' => $commission->id,
                'type_beneficiaire'        => 'proprietaire',
                'proprietaire_id'          => $vehicule->proprietaire->id,
                'livreur_id'               => null,
                'beneficiaire_nom'         => trim(
                    ($vehicule->proprietaire->prenom ?? '') . ' ' . ($vehicule->proprietaire->nom ?? '')
                ) ?: 'Propriétaire',
                'taux_commission'          => $taux,
                'montant_brut'             => $brut,
                'frais_supplementaires'    => 0,
                'montant_net'              => $brut,
                'montant_verse'            => 0,
                'statut'                   => StatutPartCommission::PENDING,
                'earned_at'                => $earnedAt->toDateString(),
                'unlock_at'                => $unlockAt->toDateString(),
            ]);
        }

        // ── Parts livreurs ────────────────────────────────────────────────────
        $equipe = $vehicule->equipe;
        if ($equipe) {
            $membres = $equipe->membres()->with('livreur')->get();
            foreach ($membres as $membre) {
                $taux = (float) ($membre->taux_commission ?? 0);
                $brut = round($montantTotal * $taux / 100, 2);
                $nomLivreur = $membre->livreur
                    ? trim(($membre->livreur->prenom ?? '') . ' ' . ($membre->livreur->nom ?? ''))
                    : "Livreur #{$membre->livreur_id}";
                $unlockAt = CommissionLogistiquePart::calculerUnlockAt('livreur', $earnedAt);

                CommissionLogistiquePart::create([
                    'commission_logistique_id' => $commission->id,
                    'type_beneficiaire'        => 'livreur',
                    'livreur_id'               => $membre->livreur_id,
                    'proprietaire_id'          => null,
                    'beneficiaire_nom'         => $nomLivreur ?: "Livreur #{$membre->livreur_id}",
                    'taux_commission'          => $taux,
                    'montant_brut'             => $brut,
                    'frais_supplementaires'    => 0,
                    'montant_net'              => $brut,
                    'montant_verse'            => 0,
                    'statut'                   => StatutPartCommission::PENDING,
                    'earned_at'                => $earnedAt->toDateString(),
                    'unlock_at'                => $unlockAt->toDateString(),
                ]);
            }
        }
    }
}
