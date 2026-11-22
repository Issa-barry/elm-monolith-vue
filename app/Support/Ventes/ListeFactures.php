<?php

namespace App\Support\Ventes;

use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Liste des factures de vente paginée côté serveur : totaux calculés en SQL sur TOUTES les factures
 * filtrées (jamais sur la page affichée) et tri des colonnes du tableau sur l'ensemble des résultats.
 *
 * Mêmes règles que le calcul historique par les accesseurs de FactureVente :
 * - Total : factures non annulées (montant net) ;
 * - Restant à encaisser : factures ni payées ni annulées, somme des restes ;
 * - Impayées / Partielles : statut Impayée / Partiellement payée, somme des restes ;
 * - Soldées : statut Payée, somme des montants nets ;
 * - reste d'une facture = max(0, net − arrondi(encaissé − arrondi(remboursé))) (montant_restant),
 *   l'encaissé étant additionné facture par facture pour ne jamais multiplier un montant par une
 *   jointure.
 */
final class ListeFactures
{
    /** Colonnes triables du tableau (clé = champ affiché côté page). */
    public const TRIS = [
        'reference', 'site_nom', 'quantite_totale', 'montant_net', 'montant_encaisse',
        'montant_restant', 'statut_label', 'created_at',
    ];

    public const PAR_PAGE = 20;

    /**
     * Totaux des cartes et nombre de factures filtrées (annulées comprises), qui sert aussi de total à
     * la pagination : un seul passage sur les factures.
     *
     * @param  Builder<FactureVente>  $factures  requête filtrée de la liste
     * @return array{totaux: array{total: float, nb_total: int, total_a_encaisser: float, nb_impayees: int, montant_impayees: float, nb_partielles: int, montant_partielles: float, nb_payees: int, montant_payees: float}, nb_resultats: int}
     */
    public static function totaux(Builder $factures): array
    {
        $annulee = StatutFactureVente::ANNULEE->value;
        $payee = StatutFactureVente::PAYEE->value;
        $impayee = StatutFactureVente::IMPAYEE->value;
        $partiel = StatutFactureVente::PARTIEL->value;
        $reste = self::resteSql();

        $ligne = DB::query()->fromSub(self::lignes($factures), 't')->selectRaw(
            "COUNT(*) as nb_resultats,
             COUNT(CASE WHEN t.statut_facture <> ? THEN 1 END) as nb_total,
             COALESCE(SUM(CASE WHEN t.statut_facture <> ? THEN t.montant_net END), 0) as total,
             COALESCE(SUM(CASE WHEN t.statut_facture NOT IN (?, ?) THEN {$reste} END), 0) as total_a_encaisser,
             COUNT(CASE WHEN t.statut_facture = ? THEN 1 END) as nb_impayees,
             COALESCE(SUM(CASE WHEN t.statut_facture = ? THEN {$reste} END), 0) as montant_impayees,
             COUNT(CASE WHEN t.statut_facture = ? THEN 1 END) as nb_partielles,
             COALESCE(SUM(CASE WHEN t.statut_facture = ? THEN {$reste} END), 0) as montant_partielles,
             COUNT(CASE WHEN t.statut_facture = ? THEN 1 END) as nb_payees,
             COALESCE(SUM(CASE WHEN t.statut_facture = ? THEN t.montant_net END), 0) as montant_payees",
            [$annulee, $annulee, $payee, $annulee, $impayee, $impayee, $partiel, $partiel, $payee, $payee],
        )->first();

        return [
            'totaux' => [
                'total' => (float) $ligne->total,
                'nb_total' => (int) $ligne->nb_total,
                'total_a_encaisser' => (float) $ligne->total_a_encaisser,
                'nb_impayees' => (int) $ligne->nb_impayees,
                'montant_impayees' => (float) $ligne->montant_impayees,
                'nb_partielles' => (int) $ligne->nb_partielles,
                'montant_partielles' => (float) $ligne->montant_partielles,
                'nb_payees' => (int) $ligne->nb_payees,
                'montant_payees' => (float) $ligne->montant_payees,
            ],
            'nb_resultats' => (int) $ligne->nb_resultats,
        ];
    }

    /**
     * Suivi des sommes encore dues, toutes périodes confondues : mêmes définitions que les cartes
     * « Restant à encaisser » et « Impayées », sur la requête filtrée SANS la période. Seules les
     * factures encore dues sont lues.
     *
     * @param  Builder<FactureVente>  $factures  requête filtrée de la liste, période non appliquée
     * @return array{total_a_encaisser: float, nb_impayees: int, montant_impayees: float}
     */
    public static function suiviToutesPeriodes(Builder $factures): array
    {
        $dues = (clone $factures)->whereNotIn('factures_ventes.statut_facture', [
            StatutFactureVente::PAYEE->value, StatutFactureVente::ANNULEE->value,
        ]);
        $impayee = StatutFactureVente::IMPAYEE->value;
        $reste = self::resteSql();

        $ligne = DB::query()->fromSub(self::lignes($dues), 't')->selectRaw(
            "COALESCE(SUM({$reste}), 0) as total_a_encaisser,
             COUNT(CASE WHEN t.statut_facture = ? THEN 1 END) as nb_impayees,
             COALESCE(SUM(CASE WHEN t.statut_facture = ? THEN {$reste} END), 0) as montant_impayees",
            [$impayee, $impayee],
        )->first();

        return [
            'total_a_encaisser' => (float) $ligne->total_a_encaisser,
            'nb_impayees' => (int) $ligne->nb_impayees,
            'montant_impayees' => (float) $ligne->montant_impayees,
        ];
    }

    /**
     * Tri demandé (colonne connue) ou ordre par défaut (plus récentes d'abord) ; l'identifiant
     * départage les égalités pour que les pages restent stables.
     *
     * @param  Builder<FactureVente>  $factures
     */
    public static function trier(Builder $factures, ?string $tri, string $sens): void
    {
        $sens = $sens === 'asc' ? 'asc' : 'desc';
        $factures->reorder();

        match ($tri) {
            'reference' => $factures->orderBy('factures_ventes.reference', $sens),
            'montant_net' => $factures->orderBy('factures_ventes.montant_net', $sens),
            'montant_encaisse' => $factures->orderBy(self::sommeEncaissements(), $sens),
            // Même ordre que le reste affiché : le plancher à zéro range les trop-perçus avec les soldées.
            'montant_restant' => $factures->orderByRaw(
                'CASE WHEN '.self::resteBrutSql().' > 0 THEN '.self::resteBrutSql().' ELSE 0 END '.$sens,
            ),
            'site_nom' => $factures->orderBy(
                DB::table('commandes_ventes')
                    ->join('sites', 'sites.id', '=', 'commandes_ventes.site_id')
                    ->select('sites.nom')
                    ->whereColumn('commandes_ventes.id', 'factures_ventes.commande_vente_id')
                    ->whereNull('sites.deleted_at')
                    ->limit(1),
                $sens,
            ),
            'quantite_totale' => $factures->orderBy(
                DB::table('commande_vente_lignes')
                    ->selectRaw('COALESCE(SUM(COALESCE(quantite_livree, quantite_chargee, quantite_demandee, 0)), 0)')
                    ->whereColumn('commande_vente_lignes.commande_vente_id', 'factures_ventes.commande_vente_id'),
                $sens,
            ),
            'statut_label' => $factures->orderByRaw(self::libelleStatutSql().' '.$sens, self::libellesStatut()),
            default => $factures->orderBy('factures_ventes.created_at', $tri === 'created_at' ? $sens : 'desc'),
        };

        $factures->orderBy('factures_ventes.id', $tri === null ? 'desc' : $sens);
    }

    /**
     * Une ligne par facture filtrée : statut, net, remboursé et somme de ses encaissements.
     *
     * @param  Builder<FactureVente>  $factures
     */
    private static function lignes(Builder $factures): QueryBuilder
    {
        return (clone $factures)->setEagerLoads([])->reorder()->toBase()
            ->select('factures_ventes.id', 'factures_ventes.statut_facture', 'factures_ventes.montant_net', 'factures_ventes.montant_rembourse')
            ->selectSub(self::sommeEncaissements(), 'encaisse');
    }

    /** Reste d'une ligne de lignes() : max(0, net − arrondi(encaissé − arrondi(remboursé))). */
    private static function resteSql(): string
    {
        $brut = 't.montant_net - ROUND(t.encaisse - ROUND(t.montant_rembourse, 2), 2)';

        return "CASE WHEN {$brut} > 0 THEN {$brut} ELSE 0 END";
    }

    /** Net − encaissé net d'une facture de la requête principale (sans plancher). */
    private static function resteBrutSql(): string
    {
        return 'factures_ventes.montant_net - ROUND(('.self::sommeEncaissements()->toSql().') - ROUND(factures_ventes.montant_rembourse, 2), 2)';
    }

    /** Somme des encaissements d'une facture (sous-requête corrélée sur factures_ventes.id). */
    private static function sommeEncaissements(): QueryBuilder
    {
        return DB::table('encaissements_ventes')
            ->selectRaw('COALESCE(SUM(encaissements_ventes.montant), 0)')
            ->whereColumn('encaissements_ventes.facture_vente_id', 'factures_ventes.id');
    }

    /** Libellé du statut (StatutFactureVente::label()) pour trier comme la colonne affichée. */
    private static function libelleStatutSql(): string
    {
        $cas = collect(StatutFactureVente::cases())
            ->map(fn () => 'WHEN factures_ventes.statut_facture = ? THEN ?')
            ->implode(' ');

        return "CASE {$cas} ELSE '' END";
    }

    /** @return list<string> */
    private static function libellesStatut(): array
    {
        return collect(StatutFactureVente::cases())
            ->flatMap(fn (StatutFactureVente $s) => [$s->value, $s->label()])
            ->values()
            ->all();
    }
}
