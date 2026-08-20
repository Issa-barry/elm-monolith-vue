<?php

namespace App\Support\Commission;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use Illuminate\Support\Collection;

/**
 * Ventile un ensemble de CommissionEnveloppePart (V2) en 4 compartiments mutuellement exclusifs
 * qui se somment exactement au total généré — décision produit du 20/08/2026 (« visible ne veut
 * pas dire payable ») : une commission CREEE existe déjà en base et doit rester visible/comptée,
 * mais ne doit jamais entrer dans le montant réellement exigible tant que sa période n'est pas
 * validée.
 *
 * - total_genere       : Σ montant_a_payer, tous statuts sauf ANNULEE (une commission annulée
 *                         n'a jamais représenté de créance réelle — comportement déjà établi
 *                         ailleurs, cf. SolvabiliteService/FactureVente).
 * - en_attente_periode : Σ montant_a_payer des parts encore CREEE. Robuste par construction,
 *                        jamais par re-résolution de période : CommissionAdjustmentService::
 *                        activerCommissionsCreeesV2() est l'UNIQUE bascule CREEE → IMPAYE/PAYE,
 *                        déclenchée exactement à la validation de la période couvrante — une
 *                        part encore CREEE n'a donc jamais pu appartenir à une période validée.
 * - payable            : Σ montant_restant des parts IMPAYE/PARTIEL — déjà passées par la
 *                        bascule ci-dessus, donc déjà rattachées à une période validée.
 * - deja_paye          : Σ montant_verse, tous statuts actifs.
 *
 * total_genere === en_attente_periode + payable + deja_paye (à l'arrondi près) : jamais un
 * quatrième nombre indépendant, toujours une partition exacte du total généré.
 */
final class CommissionKpiBuckets
{
    /**
     * @param  Collection<int, CommissionEnveloppePart>  $parts
     * @return array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}
     */
    public static function calculer(Collection $parts): array
    {
        $actives = $parts->filter(
            fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::ANNULEE
        );

        $totalGenere = (float) $actives->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);

        $enAttentePeriode = (float) $actives
            ->filter(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::CREEE)
            ->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);

        $payable = (float) $actives
            ->filter(fn (CommissionEnveloppePart $p) => in_array(
                $p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true
            ))
            ->sum(fn (CommissionEnveloppePart $p) => $p->montant_restant);

        $dejaPaye = (float) $actives->sum('montant_verse');

        return [
            'total_genere' => round($totalGenere, 2),
            'en_attente_periode' => round($enAttentePeriode, 2),
            'payable' => round($payable, 2),
            'deja_paye' => round($dejaPaye, 2),
        ];
    }

    /**
     * Fusionne plusieurs jeux de compartiments déjà calculés (ex: agrégat multi-bénéficiaires
     * pour les KPI globaux d'un écran d'index) — simple somme composante par composante.
     *
     * @param  iterable<array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}>  $buckets
     * @return array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}
     */
    public static function fusionner(iterable $buckets): array
    {
        $total = ['total_genere' => 0.0, 'en_attente_periode' => 0.0, 'payable' => 0.0, 'deja_paye' => 0.0];

        foreach ($buckets as $b) {
            $total['total_genere'] += $b['total_genere'];
            $total['en_attente_periode'] += $b['en_attente_periode'];
            $total['payable'] += $b['payable'];
            $total['deja_paye'] += $b['deja_paye'];
        }

        return [
            'total_genere' => round($total['total_genere'], 2),
            'en_attente_periode' => round($total['en_attente_periode'], 2),
            'payable' => round($total['payable'], 2),
            'deja_paye' => round($total['deja_paye'], 2),
        ];
    }
}
