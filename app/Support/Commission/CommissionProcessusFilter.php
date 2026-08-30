<?php

namespace App\Support\Commission;

use App\Models\CommissionProcessus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre optionnel par processus (vente/distribution_client/logistique_transfert) pour les écrans
 * de reporting Comptabilité qui interrogent CommissionEnveloppePart — jamais appliqué à la
 * machinerie de paiement/période (PeriodeCalculatorService, CommissionEnveloppePartAllocationService),
 * qui doit au contraire toujours unir tous les processus (cf. docs/commissions.md).
 */
class CommissionProcessusFilter
{
    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return [
            ['value' => CommissionProcessus::CODE_VENTE, 'label' => 'Vente'],
            ['value' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 'label' => 'Distribution client'],
            ['value' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 'label' => 'Transfert logistique'],
        ];
    }

    /**
     * Applique le filtre sur une requête CommissionEnveloppePart si $processusCode est non vide —
     * sans effet sinon (vue consolidée par défaut, cf. décision produit : "les vues comptables
     * globales peuvent naturellement consolider plusieurs processus").
     */
    public static function appliquer(Builder $query, ?string $processusCode): Builder
    {
        if (! $processusCode) {
            return $query;
        }

        return $query->whereHas('enveloppe.processus', fn ($q) => $q->where('code', $processusCode));
    }
}
