<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Models\CommissionPart;
use App\Models\PaiementCommissionVente;
use App\Models\PaiementCommissionVenteItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommissionVentePaiementService
{
    /**
     * Enregistre un paiement groupé pour un bénéficiaire (tous véhicules confondus).
     *
     * Allocation FIFO par date de commission (created_at). Seules les parts
     * dont la date d'éligibilité est dépassée sont incluses.
     *
     * @throws InvalidArgumentException si montant ≤ 0 ou > disponible.
     */
    public static function payer(
        int $organizationId,
        string $type,
        int $beneficiaireId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): PaiementCommissionVente {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponibles($organizationId, $type, $beneficiaireId);
        $totalDisponible = $parts->sum(fn ($p) => max(0.0, (float) $p->montant_net - (float) $p->montant_verse));

        if ($montant > $totalDisponible + 0.009) { // tolérance arrondi flottant
            throw new InvalidArgumentException(
                sprintf(
                    'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                    $montant,
                    $totalDisponible
                )
            );
        }

        $beneficiaireNom = $parts->first()?->beneficiaire_nom ?? 'Inconnu';

        return DB::transaction(function () use (
            $organizationId, $type, $beneficiaireId, $beneficiaireNom,
            $montant, $modePaiement, $paidAt, $note, $parts
        ) {
            $paiement = PaiementCommissionVente::create([
                'organization_id' => $organizationId,
                'type_beneficiaire' => $type,
                'livreur_id' => $type === 'livreur' ? $beneficiaireId : null,
                'proprietaire_id' => $type === 'proprietaire' ? $beneficiaireId : null,
                'beneficiaire_nom' => $beneficiaireNom,
                'montant' => $montant,
                'mode_paiement' => $modePaiement,
                'note' => $note,
                'paid_at' => $paidAt,
                'created_by' => Auth::id(),
            ]);

            // ── Allocation FIFO ───────────────────────────────────────────────
            $restant = $montant;

            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }

                $partRestant = max(0.0, (float) $part->montant_net - (float) $part->montant_verse);
                if ($partRestant <= 0) {
                    continue;
                }

                $alloue = min($restant, $partRestant);

                PaiementCommissionVenteItem::create([
                    'paiement_id' => $paiement->id,
                    'commission_part_id' => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);

                // Recalcule montant_verse + statut sur la part, puis propage à la commission
                $part->recalculStatut();

                $restant = round($restant - $alloue, 2);
            }

            return $paiement->load('items');
        });
    }

    /**
     * Retourne les parts disponibles (non soldées, date d'éligibilité dépassée)
     * pour un bénéficiaire, triées FIFO par date de commission.
     *
     * @return Collection<CommissionPart>
     */
    public static function partsDisponibles(
        int $organizationId,
        string $type,
        int $beneficiaireId
    ): Collection {
        $now = now();

        $query = CommissionPart::with('commission')
            ->join('commissions_ventes AS cv_fifo', 'cv_fifo.id', '=', 'commission_parts.commission_vente_id')
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('commission_parts.type_beneficiaire', $type)
            ->where('commission_parts.statut', '!=', StatutCommission::ANNULEE->value)
            ->whereRaw('commission_parts.montant_verse < commission_parts.montant_net')
            ->orderBy('cv_fifo.created_at')
            ->orderBy('commission_parts.id')
            ->select('commission_parts.*');

        if ($type === 'livreur') {
            $query->where('commission_parts.livreur_id', $beneficiaireId);
        } else {
            $query->where('commission_parts.proprietaire_id', $beneficiaireId);
        }

        $allParts = $query->get();

        // Filtre règle d'éligibilité : livreur +14j, propriétaire 1er du mois suivant
        return $allParts->filter(function (CommissionPart $part) use ($now, $type) {
            $earnedAt = $part->commission?->created_at;
            if (! $earnedAt) {
                return true;
            }

            $disponibleAt = match ($type) {
                'livreur' => $earnedAt->clone()->addDays(14),
                'proprietaire' => $earnedAt->clone()->addMonthNoOverflow()->startOfMonth(),
                default => null,
            };

            return ! $disponibleAt || $now->greaterThanOrEqualTo($disponibleAt);
        })->values();
    }

    /**
     * Calcule le total disponible maintenant pour un bénéficiaire.
     */
    public static function totalDisponible(int $organizationId, string $type, int $beneficiaireId): float
    {
        return (float) self::partsDisponibles($organizationId, $type, $beneficiaireId)
            ->sum(fn ($p) => max(0.0, (float) $p->montant_net - (float) $p->montant_verse));
    }
}
