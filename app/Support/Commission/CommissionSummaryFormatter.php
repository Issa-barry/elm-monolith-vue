<?php

namespace App\Support\Commission;

final class CommissionSummaryFormatter
{
    /**
     * Garantit le même format de sortie (commission_summary) pour les pages détail
     * Commission vente / Commission logistique / Commission propriétaire,
     * sans recalculer quoi que ce soit : chaque controller passe les montants
     * qu'il calcule déjà avec sa propre logique métier.
     *
     * @return array{brut_cumule: float, frais: float, net_a_payer: float, deja_paye: float, reste_a_payer: float}
     */
    public static function format(
        float $brutCumule,
        float $frais,
        float $netAPayer,
        float $dejaPaye,
        float $resteAPayer,
    ): array {
        return [
            'brut_cumule' => round($brutCumule, 2),
            'frais' => round($frais, 2),
            'net_a_payer' => round($netAPayer, 2),
            'deja_paye' => round($dejaPaye, 2),
            'reste_a_payer' => round($resteAPayer, 2),
        ];
    }
}
