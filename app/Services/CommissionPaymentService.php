<?php

namespace App\Services;

use App\Enums\StatutPartCommission;
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
     *
     * L'allocation suit un ordre FIFO : les parts dont earned_at est le plus
     * ancien sont soldées en premier. Le montant saisi peut couvrir plusieurs
     * parts ou seulement une fraction d'une part.
     *
     * @param  Vehicule  $vehicule
     * @param  string    $beneficiaryType  livreur|proprietaire
     * @param  int       $beneficiaryId    livreur_id ou proprietaire_id
     * @param  float     $montant          montant total à payer
     * @param  string    $modePaiement
     * @param  string    $paidAt           date ISO Y-m-d
     * @param  string|null $note
     *
     * @throws InvalidArgumentException
     */
    public static function payer(
        Vehicule $vehicule,
        string   $beneficiaryType,
        int      $beneficiaryId,
        float    $montant,
        string   $modePaiement,
        string   $paidAt,
        ?string  $note = null
    ): CommissionPayment {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        // Récupère les parts disponibles (available | partially_paid) pour ce bénéficiaire,
        // triées FIFO par earned_at.
        $parts = self::partsDisponibles($vehicule, $beneficiaryType, $beneficiaryId);

        $totalDisponible = $parts->sum(fn ($p) => $p->montant_restant);

        if ($montant > $totalDisponible + 0.009) { // tolérance flottant
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        // Nom du bénéficiaire (depuis la première part disponible)
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

            // ── Allocation FIFO ───────────────────────────────────────────────
            $restant = $montant;

            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }

                $montantRestant = (float) $part->montant_restant;
                $alloue         = min($restant, $montantRestant);

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

    /**
     * Parts disponibles (payables) pour un bénéficiaire + véhicule, triées FIFO.
     *
     * @return Collection<CommissionLogistiquePart>
     */
    public static function partsDisponibles(
        Vehicule $vehicule,
        string   $beneficiaryType,
        int      $beneficiaryId
    ): Collection {
        $query = CommissionLogistiquePart::query()
            ->whereIn('statut', [
                StatutPartCommission::AVAILABLE->value,
                StatutPartCommission::PARTIAL->value,
            ])
            ->where('type_beneficiaire', $beneficiaryType)
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                  ->where('organization_id', $vehicule->organization_id);
            })
            ->orderBy('earned_at')   // FIFO
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
     * Utilisé par CommissionVehiculeController::show().
     *
     * @return array{
     *   livreurs: array<int, array{id:int, nom:string, pending:float, available:float, paid:float}>,
     *   proprietaires: array<int, array{id:int, nom:string, pending:float, available:float, paid:float}>
     * }
     */
    public static function soldesParVehicule(Vehicule $vehicule): array
    {
        $rows = CommissionLogistiquePart::query()
            ->selectRaw(
                'type_beneficiaire,
                 COALESCE(livreur_id, proprietaire_id) AS beneficiary_id,
                 beneficiaire_nom,
                 SUM(CASE WHEN statut = ? THEN montant_net ELSE 0 END)                                       AS pending,
                 SUM(CASE WHEN statut IN (?,?) THEN CASE WHEN montant_net > montant_verse THEN montant_net - montant_verse ELSE 0 END ELSE 0 END) AS available,
                 SUM(CASE WHEN statut = ? THEN montant_net ELSE 0 END)                                       AS paid',
                [
                    StatutPartCommission::PENDING->value,
                    StatutPartCommission::AVAILABLE->value,
                    StatutPartCommission::PARTIAL->value,
                    StatutPartCommission::PAID->value,
                ]
            )
            ->whereHas('commission', function ($q) use ($vehicule) {
                $q->where('vehicule_id', $vehicule->id)
                  ->where('organization_id', $vehicule->organization_id);
            })
            ->where('statut', '!=', StatutPartCommission::CANCELLED->value)
            ->groupBy('type_beneficiaire', 'beneficiary_id', 'beneficiaire_nom')
            ->get();

        $result = ['livreurs' => [], 'proprietaires' => []];

        foreach ($rows as $row) {
            $key  = $row->type_beneficiaire === 'livreur' ? 'livreurs' : 'proprietaires';
            $result[$key][] = [
                'id'        => (int) $row->beneficiary_id,
                'type'      => $row->type_beneficiaire,
                'nom'       => $row->beneficiaire_nom,
                'pending'   => (float) $row->pending,
                'available' => (float) $row->available,
                'paid'      => (float) $row->paid,
            ];
        }

        return $result;
    }

    /**
     * Relevé détaillé des parts (accruals) pour un bénéficiaire + véhicule.
     * Utilisé par CommissionBeneficiaireController::show().
     */
    public static function releve(
        Vehicule $vehicule,
        string   $beneficiaryType,
        int      $beneficiaryId
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
        ->where('statut', '!=', StatutPartCommission::CANCELLED->value)
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
