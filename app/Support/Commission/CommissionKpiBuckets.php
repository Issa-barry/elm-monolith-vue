<?php

namespace App\Support\Commission;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ventile un ensemble de CommissionEnveloppePart (V2) en 4 compartiments mutuellement exclusifs
 * qui se somment exactement au total généré — décision produit du 20/08/2026 (« visible ne veut
 * pas dire payable ») : une commission CREEE existe déjà en base et doit rester visible/comptée,
 * mais ne doit jamais être présentée comme *payable* tant que sa période n'est pas validée.
 *
 * Ces compartiments restent la source de vérité pour ce qui est *payable* (et donc pour le
 * statut affiché, cf. $statutGlobal dans CommissionVenteController). Ils sont indépendants du
 * *montant retenu courant* (net_a_payer/reste_a_payer, cf. CommissionVenteCalculatorService::
 * calculerResume()) : depuis la décision produit du 29/08/2026, ce montant est calculé sur
 * TOUTES les parts actives (CREEE incluse) — la validation conditionne le droit au paiement,
 * jamais l'affichage du montant réel actuellement retenu.
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
     * Les mêmes compartiments que calculer(), plus les trois sommes du résumé (brut, à payer,
     * versé), calculés par la base pour chaque bénéficiaire de la requête : un seul passage, sans
     * lire les parts une à une (sur tout l'historique, des dizaines de milliers de parts dont chaque
     * montant décimal coûte une conversion à chaque lecture).
     *
     * Mêmes définitions que les accesseurs de CommissionEnveloppePart : montant_a_payer =
     * montant_actuel s'il est renseigné, sinon montant_net ; montant_restant = max(0, à payer −
     * versé). Compartiments arrondis au centime comme calculer().
     *
     * @param  Builder<CommissionEnveloppePart>  $parts  requête filtrée des parts
     * @return Collection<string, array{montant_brut: float, montant_a_payer: float, montant_verse: float, total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}>
     */
    public static function sommesParBeneficiaire(Builder $parts): Collection
    {
        $aPayer = 'COALESCE(montant_actuel, montant_net)';
        [$compartiments, $liaisons] = self::compartimentsSql();

        return (clone $parts)->setEagerLoads([])->reorder()->toBase()
            ->select('beneficiaire_id')
            ->selectRaw(
                "COALESCE(SUM(montant_brut), 0) as montant_brut,
                 COALESCE(SUM({$aPayer}), 0) as montant_a_payer,
                 COALESCE(SUM(montant_verse), 0) as montant_verse,
                 {$compartiments}",
                $liaisons,
            )
            ->groupBy('beneficiaire_id')
            ->get()
            ->mapWithKeys(fn (object $l) => [(string) $l->beneficiaire_id => [
                'montant_brut' => (float) $l->montant_brut,
                'montant_a_payer' => (float) $l->montant_a_payer,
                'montant_verse' => (float) $l->montant_verse,
                'total_genere' => round((float) $l->total_genere, 2),
                'en_attente_periode' => round((float) $l->en_attente_periode, 2),
                'payable' => round((float) $l->payable, 2),
                'deja_paye' => round((float) $l->deja_paye, 2),
            ]]);
    }

    /**
     * Sommes SQL des quatre compartiments (total_genere, en_attente_periode, payable, deja_paye),
     * mêmes définitions que calculer(). `$table` préfixe les colonnes des parts quand la requête
     * joint commission_enveloppes, qui a aussi `statut` et `montant_verse`.
     *
     * @return array{0: string, 1: list<string>} expression SELECT et ses liaisons
     */
    public static function compartimentsSql(string $table = ''): array
    {
        $p = $table === '' ? '' : $table.'.';
        $aPayer = "COALESCE({$p}montant_actuel, {$p}montant_net)";
        $active = "{$p}statut <> ?";
        $annulee = StatutCommission::ANNULEE->value;

        return [
            "COALESCE(SUM(CASE WHEN {$active} THEN {$aPayer} END), 0) as total_genere,
                 COALESCE(SUM(CASE WHEN {$active} AND {$p}statut = ? THEN {$aPayer} END), 0) as en_attente_periode,
                 COALESCE(SUM(CASE WHEN {$active} AND {$p}statut IN (?, ?) AND {$aPayer} - {$p}montant_verse > 0 THEN {$aPayer} - {$p}montant_verse END), 0) as payable,
                 COALESCE(SUM(CASE WHEN {$active} THEN {$p}montant_verse END), 0) as deja_paye",
            [$annulee, $annulee, StatutCommission::CREEE->value, $annulee, StatutCommission::IMPAYE->value, StatutCommission::PARTIEL->value, $annulee],
        ];
    }

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
     * Même calcul que calculer(), sur les lignes légères de LecturePartsCommission::lignes()
     * (montants déjà arrondis au centime comme le cast decimal:2), additionnées dans le même ordre.
     *
     * @param  iterable<object{statut: string, a_payer: float, verse: float}>  $lignes
     * @return array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float}
     */
    public static function calculerLignes(iterable $lignes): array
    {
        $annulee = StatutCommission::ANNULEE->value;
        $creee = StatutCommission::CREEE->value;
        $aEncaisser = [StatutCommission::IMPAYE->value, StatutCommission::PARTIEL->value];
        $totalGenere = $enAttente = $payable = $dejaPaye = 0;

        foreach ($lignes as $l) {
            if ($l->statut === $annulee) {
                continue;
            }
            $totalGenere += $l->a_payer;
            $dejaPaye += $l->verse;
            if ($l->statut === $creee) {
                $enAttente += $l->a_payer;
            } elseif (in_array($l->statut, $aEncaisser, true)) {
                $payable += max(0.0, $l->a_payer - $l->verse);
            }
        }

        return [
            'total_genere' => round((float) $totalGenere, 2),
            'en_attente_periode' => round((float) $enAttente, 2),
            'payable' => round((float) $payable, 2),
            'deja_paye' => round((float) $dejaPaye, 2),
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
