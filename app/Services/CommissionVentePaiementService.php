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
        string $organizationId,
        string $type,
        string $beneficiaireId,
        float $montant,
        string $modePaiement,
        string $paidAt,
        ?string $note = null
    ): PaiementCommissionVente {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être supérieur à 0.');
        }

        $parts = self::partsDisponibles($organizationId, $type, $beneficiaireId);
        $totalDisponible = self::totalDisponible($organizationId, $type, $beneficiaireId, $parts);

        if ($montant > $totalDisponible + 0.009) { // tolérance arrondi flottant
            throw new InvalidArgumentException(
                self::messageSoldeInsuffisant($organizationId, $type, $beneficiaireId, $montant, $totalDisponible)
            );
        }

        $touched = PeriodePayabilityChecker::touchedUntilAmount(
            $parts,
            $montant,
            fn ($p) => (float) $p->montant_restant
        );
        PeriodePayabilityChecker::assertPartsPayable($touched);

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

                $partRestant = (float) $part->montant_restant;
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
        string $organizationId,
        string $type,
        string $beneficiaireId
    ): Collection {
        $query = CommissionPart::with('commission')
            ->join('commissions_ventes AS cv_fifo', 'cv_fifo.id', '=', 'commission_parts.commission_vente_id')
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('commission_parts.type_beneficiaire', $type)
            ->whereNotIn('commission_parts.statut', [StatutCommission::CREEE->value, StatutCommission::ANNULEE->value])
            ->whereRaw('commission_parts.montant_verse < COALESCE(commission_parts.montant_actuel, commission_parts.montant_net)')
            ->orderBy('cv_fifo.created_at')
            ->orderBy('commission_parts.id')
            ->select('commission_parts.*');

        if ($type === 'livreur') {
            $query->where('commission_parts.livreur_id', $beneficiaireId);
        } else {
            $query->where('commission_parts.proprietaire_id', $beneficiaireId);
        }

        // Toutes les parts non soldées sont éligibles au paiement.
        // La date d'éligibilité (+14j livreur, 1er mois suivant propriétaire) est
        // affichée à titre indicatif dans l'UI ("Disponible maintenant") mais ne
        // bloque plus le paiement : l'admin conserve la flexibilité de payer à
        // tout moment.
        return $query->get()->values();
    }

    /**
     * Calcule le total disponible maintenant pour un bénéficiaire, net des frais
     * (Depense validées) déjà déduits dans l'index/le détail — pour ne jamais
     * autoriser un paiement supérieur au "reste à payer" affiché à l'écran.
     */
    public static function totalDisponible(
        string $organizationId,
        string $type,
        string $beneficiaireId,
        ?Collection $parts = null
    ): float {
        $parts ??= self::partsDisponibles($organizationId, $type, $beneficiaireId);

        $totalParts = (float) $parts->sum(fn ($p) => (float) $p->montant_restant);

        $fraisDepenses = $type === 'livreur'
            ? CommissionVenteCalculatorService::fraisDepenseLivreur($organizationId, $beneficiaireId)
            : 0.0;

        return max(0.0, $totalParts - $fraisDepenses);
    }

    /**
     * Message d'erreur explicite : un simple "solde disponible : 0.00 GNF"
     * ne dit pas à l'admin POURQUOI — commandes pas encore activées, frais
     * déjà déduits, ou déjà tout payé sont trois situations différentes.
     */
    private static function messageSoldeInsuffisant(
        string $organizationId,
        string $type,
        string $beneficiaireId,
        float $montant,
        float $totalDisponible
    ): string {
        if ($totalDisponible > 0.009) {
            return sprintf(
                'Le montant saisi (%.2f GNF) dépasse le solde disponible (%.2f GNF).',
                $montant,
                $totalDisponible
            );
        }

        $partsQuery = CommissionPart::whereHas('commission', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('type_beneficiaire', $type);
        $type === 'livreur'
            ? $partsQuery->where('livreur_id', $beneficiaireId)
            : $partsQuery->where('proprietaire_id', $beneficiaireId);

        $nbCreee = $partsQuery->clone()->where('statut', StatutCommission::CREEE->value)->count();
        $nbAutres = $partsQuery->clone()->where('statut', '!=', StatutCommission::CREEE->value)->count();

        if ($nbCreee > 0 && $nbAutres === 0) {
            return 'Aucune commission n\'est encore due : les commandes correspondantes n\'ont pas encore validé leur chargement.';
        }

        $fraisDepenses = $type === 'livreur'
            ? CommissionVenteCalculatorService::fraisDepenseLivreur($organizationId, $beneficiaireId)
            : 0.0;

        if ($fraisDepenses > 0.009) {
            return sprintf(
                'Solde disponible : 0,00 GNF — %s GNF de dépenses validées ont déjà été déduites du net à payer de ce bénéficiaire.',
                number_format($fraisDepenses, 2, ',', ' ')
            );
        }

        if ($nbAutres > 0) {
            return 'Toutes les commissions disponibles de ce bénéficiaire sont déjà payées.';
        }

        return 'Aucune commission disponible pour ce bénéficiaire.';
    }
}
