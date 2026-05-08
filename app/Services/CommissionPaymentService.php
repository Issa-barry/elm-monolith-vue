<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\CommissionPaymentItem;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommissionPaymentService
{
    /**
     * Enregistre un paiement groupé pour un bénéficiaire + véhicule.
     * Allocation FIFO : les parts dont earned_at est le plus ancien sont soldées en premier.
     *
     * @param  string  $beneficiaryType  livreur|proprietaire
     * @param  string  $beneficiaryId
     * @param  float   $montant          montant total à payer
     * @param  string  $paidAt           date ISO Y-m-d
     *
     * @throws InvalidArgumentException
     */
    public static function payer(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): CommissionPayment {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponibles($vehicule, $beneficiaryType, $beneficiaryId);
        $totalDisponible = $parts->sum(fn ($p) => $p->montant_restant);

        if ($montant > $totalDisponible + 0.009) {
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        $beneficiaryNom = $parts->first()?->beneficiaire_nom ?? 'Inconnu';

        return DB::transaction(function () use (
            $vehicule, $beneficiaryType, $beneficiaryId, $beneficiaryNom,
            $montant, $modePaiement, $paidAt, $note, $parts
        ) {
            $payment = CommissionPayment::create([
                'organization_id'  => $vehicule->organization_id,
                'vehicule_id'      => $vehicule->id,
                'livreur_id'       => $beneficiaryType === 'livreur' ? $beneficiaryId : null,
                'proprietaire_id'  => $beneficiaryType === 'proprietaire' ? $beneficiaryId : null,
                'beneficiary_type' => $beneficiaryType,
                'beneficiary_nom'  => $beneficiaryNom,
                'montant'          => $montant,
                'mode_paiement'    => $modePaiement,
                'note'             => $note,
                'paid_at'          => $paidAt,
                'created_by'       => Auth::id(),
            ]);

            $restant = $montant;
            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }
                $alloue = min($restant, (float) $part->montant_restant);
                CommissionPaymentItem::create([
                    'payment_id'       => $payment->id,
                    'part_id'          => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);
                $part->recalculStatut();
                $restant = round($restant - $alloue, 2);
            }

            return $payment->load('items');
        });
    }

    // ── API globale livreur (sans contrainte de véhicule) ────────────────────

    /**
     * Soldes agrégés par livreur pour toute une organisation.
     * Retourne les colonnes : livreur_id, beneficiaire_nom, impaye, paye.
     */
    public static function soldesParLivreur(string $orgId): Collection
    {
        return CommissionLogistiquePart::query()
            ->selectRaw(
                'livreur_id,
                 MAX(beneficiaire_nom) AS beneficiaire_nom,
                 SUM(CASE WHEN statut IN (?,?) THEN CASE WHEN montant_net - montant_verse > 0 THEN montant_net - montant_verse ELSE 0 END ELSE 0 END) AS impaye,
                 SUM(CASE WHEN statut = ? THEN montant_net ELSE 0 END) AS paye',
                [
                    StatutCommission::IMPAYE->value,
                    StatutCommission::PARTIEL->value,
                    StatutCommission::PAYE->value,
                ]
            )
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->groupBy('livreur_id')
            ->orderByRaw('impaye DESC')
            ->get();
    }

    /**
     * Parts payables (impayé + partiel) pour un livreur sur toute l'org, triées FIFO.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function partsDisponiblesLivreur(string $livreurId, string $orgId): Collection
    {
        return CommissionLogistiquePart::query()
            ->whereIn('statut', [
                StatutCommission::IMPAYE->value,
                StatutCommission::PARTIEL->value,
            ])
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Relevé de toutes les parts d'un livreur sur toute l'org.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function releveLivreur(string $livreurId, string $orgId): Collection
    {
        return CommissionLogistiquePart::with([
            'commission.transfert:id,reference,date_arrivee_reelle',
            'paymentItems.payment:id,paid_at,mode_paiement,montant',
        ])
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Enregistre un paiement global pour un livreur (multi-véhicules, FIFO).
     *
     * @throws InvalidArgumentException
     */
    public static function payerLivreur(
        string $livreurId,
        string $orgId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): CommissionPayment {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponiblesLivreur($livreurId, $orgId);
        $totalDisponible = $parts->sum(fn ($p) => $p->montant_restant);

        if ($montant > $totalDisponible + 0.009) {
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        $beneficiaryNom = $parts->first()?->beneficiaire_nom ?? 'Inconnu';

        return DB::transaction(function () use (
            $livreurId, $orgId, $beneficiaryNom, $montant, $modePaiement, $paidAt, $note, $parts
        ) {
            $payment = CommissionPayment::create([
                'organization_id'  => $orgId,
                'vehicule_id'      => null,
                'livreur_id'       => $livreurId,
                'proprietaire_id'  => null,
                'beneficiary_type' => 'livreur',
                'beneficiary_nom'  => $beneficiaryNom,
                'montant'          => $montant,
                'mode_paiement'    => $modePaiement,
                'note'             => $note,
                'paid_at'          => $paidAt,
                'created_by'       => Auth::id(),
            ]);

            $restant = $montant;
            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }
                $alloue = min($restant, (float) $part->montant_restant);
                CommissionPaymentItem::create([
                    'payment_id'       => $payment->id,
                    'part_id'          => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);
                $part->recalculStatut();
                $restant = round($restant - $alloue, 2);
            }

            return $payment->load('items');
        });
    }

    // ── API par véhicule (retro-compat) ──────────────────────────────────────

    /**
     * Parts payables (impayé + partiel) pour un bénéficiaire + véhicule, triées FIFO.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function partsDisponibles(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId
    ): Collection {
        $query = CommissionLogistiquePart::query()
            ->whereIn('statut', [
                StatutCommission::IMPAYE->value,
                StatutCommission::PARTIEL->value,
            ])
            ->where('type_beneficiaire', $beneficiaryType)
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->orderBy('earned_at')
            ->orderBy('id');

        if ($beneficiaryType === 'livreur') {
            $query->where('livreur_id', $beneficiaryId);
        } else {
            $query->where('proprietaire_id', $beneficiaryId);
        }

        return $query->get();
    }

    /**
     * Soldes agrégés pour un véhicule, regroupés par bénéficiaire.
     * Retourne impaye + paye par bénéficiaire.
     */
    public static function soldesParVehicule(Vehicule $vehicule): array
    {
        $rows = CommissionLogistiquePart::query()
            ->selectRaw(
                'type_beneficiaire,
                 COALESCE(livreur_id, proprietaire_id) AS beneficiary_id,
                 beneficiaire_nom,
                 SUM(CASE WHEN statut IN (?,?) THEN CASE WHEN montant_net - montant_verse > 0 THEN montant_net - montant_verse ELSE 0 END ELSE 0 END) AS impaye,
                 SUM(CASE WHEN statut = ? THEN montant_net ELSE 0 END) AS paye',
                [
                    StatutCommission::IMPAYE->value,
                    StatutCommission::PARTIEL->value,
                    StatutCommission::PAYE->value,
                ]
            )
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->groupBy('type_beneficiaire', 'beneficiary_id', 'beneficiaire_nom')
            ->get();

        $result = ['livreurs' => [], 'proprietaires' => []];

        foreach ($rows as $row) {
            $key = $row->type_beneficiaire === 'livreur' ? 'livreurs' : 'proprietaires';
            $result[$key][] = [
                'id'     => $row->beneficiary_id,
                'type'   => $row->type_beneficiaire,
                'nom'    => $row->beneficiaire_nom,
                'impaye' => (float) $row->impaye,
                'paye'   => (float) $row->paye,
            ];
        }

        return $result;
    }

    /**
     * Relevé détaillé des parts (accruals) pour un bénéficiaire + véhicule.
     */
    public static function releve(
        Vehicule $vehicule,
        string $beneficiaryType,
        string $beneficiaryId
    ): Collection {
        $query = CommissionLogistiquePart::with([
            'commission.transfert:id,reference,date_arrivee_reelle',
            'paymentItems.payment:id,paid_at,mode_paiement,montant',
        ])
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                    ->where('organization_id', $vehicule->organization_id);
            })
            ->where('type_beneficiaire', $beneficiaryType)
            ->orderBy('earned_at')
            ->orderBy('id');

        if ($beneficiaryType === 'livreur') {
            $query->where('livreur_id', $beneficiaryId);
        } else {
            $query->where('proprietaire_id', $beneficiaryId);
        }

        return $query->get();
    }
}
