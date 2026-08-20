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
     * $buckets (optionnel, V2 uniquement) : compartiments total_genere/en_attente_periode/
     * payable calculés par CommissionKpiBuckets — jamais recalculés ici, simplement fusionnés
     * tels quels dans la sortie pour que CommissionSummaryCards.vue les affiche sans prop
     * séparée. Absent (legacy, ou écran sans notion de période) : ces trois clés valent null,
     * et le composant retombe alors sur son affichage historique (brut/frais/net/payé/reste).
     *
     * @param  ?array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}  $buckets
     * @return array{brut_cumule: float, frais: float, net_a_payer: float, deja_paye: float, reste_a_payer: float, total_genere: ?float, en_attente_periode: ?float, payable: ?float}
     */
    public static function format(
        float $brutCumule,
        float $frais,
        float $netAPayer,
        float $dejaPaye,
        float $resteAPayer,
        ?array $buckets = null,
    ): array {
        return [
            'brut_cumule' => round($brutCumule, 2),
            'frais' => round($frais, 2),
            'net_a_payer' => round($netAPayer, 2),
            'deja_paye' => round($dejaPaye, 2),
            'reste_a_payer' => round($resteAPayer, 2),
            'total_genere' => $buckets['total_genere'] ?? null,
            'en_attente_periode' => $buckets['en_attente_periode'] ?? null,
            'payable' => $buckets['payable'] ?? null,
        ];
    }
}
