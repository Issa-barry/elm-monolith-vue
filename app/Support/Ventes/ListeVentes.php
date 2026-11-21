<?php

namespace App\Support\Ventes;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommissionProcessus;
use App\Models\FactureVente;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Liste des ventes paginée côté serveur : totaux calculés en SQL sur TOUTES les ventes filtrées
 * (jamais sur la page affichée) et tri des colonnes du tableau sur l'ensemble des résultats.
 *
 * Mêmes règles que les accesseurs des modèles :
 * - ventes comptées : hors Annulée et Annulée (erreur de saisie) ; clôturées : statut Clôturée ;
 * - factures retenues : celles qui ne sont pas annulées (une vente a au plus une facture : la
 *   facture reprend la référence de la vente, unique par organisation) ;
 * - déjà payé = somme des encaissements (FactureVente::montant_encaisse) ;
 * - reste à encaisser = max(0, net − arrondi(encaissé − remboursé)) par facture
 *   (FactureVente::montant_restant), les encaissements étant additionnés facture par facture
 *   pour ne jamais multiplier un montant par une jointure.
 */
final class ListeVentes
{
    /** Colonnes triables du tableau (clé = champ affiché côté page). */
    public const TRIS = [
        'reference', 'date_remise_prevue_iso', 'site_nom', 'quantite_totale', 'total_commande',
        'facture_montant_restant', 'created_at', 'processus_label', 'statut',
    ];

    public const PAR_PAGE = 20;

    /**
     * Totaux de la liste et nombre de ventes filtrées (annulées comprises), qui sert aussi de total à
     * la pagination : un seul passage sur les ventes.
     *
     * @param  Builder<CommandeVente>  $ventes  requête filtrée de la liste
     * @return array{totaux: array{total_montant: float, nb_total: int, total_a_encaisser: float, deja_paye: float, nb_cloturees: int, montant_cloturees: float}, nb_resultats: int}
     */
    public static function totaux(Builder $ventes): array
    {
        $base = (clone $ventes)->setEagerLoads([])->reorder();
        $annulees = [StatutCommandeVente::ANNULEE->value, StatutCommandeVente::ANNULEE_ERREUR_SAISIE->value];
        $cloturee = StatutCommandeVente::CLOTUREE->value;

        // Un seul passage sur les ventes filtrées : la requête filtrée devient une table dérivée
        // (aucune ambiguïté de colonne avec la facture), jointe à sa facture non annulée.
        $ventesFiltrees = (clone $base)->toBase()
            ->select('commandes_ventes.id', 'commandes_ventes.statut', 'commandes_ventes.total_commande');

        $lignes = DB::query()->fromSub($ventesFiltrees, 'v')
            ->leftJoin('factures_ventes as f', fn ($jointure) => $jointure
                ->on('f.commande_vente_id', '=', 'v.id')
                ->whereNull('f.deleted_at')
                ->where(fn ($q) => $q->whereNull('f.statut_facture')->orWhere('f.statut_facture', '!=', StatutFactureVente::ANNULEE->value)))
            ->select('v.statut', 'v.total_commande', 'f.id as facture_id', 'f.montant_net', 'f.montant_rembourse')
            ->selectSub(self::sommeEncaissements('f.id'), 'encaisse');

        $ligne = DB::query()->fromSub($lignes, 't')->selectRaw(
            'COUNT(*) as nb_resultats,
             COUNT(CASE WHEN COALESCE(t.statut, \'\') NOT IN (?, ?) THEN 1 END) as nb_total,
             COALESCE(SUM(CASE WHEN COALESCE(t.statut, \'\') NOT IN (?, ?) THEN t.total_commande END), 0) as total_montant,
             COUNT(CASE WHEN t.statut = ? THEN 1 END) as nb_cloturees,
             COALESCE(SUM(CASE WHEN t.statut = ? THEN t.total_commande END), 0) as montant_cloturees,
             COALESCE(SUM(CASE WHEN t.facture_id IS NOT NULL THEN t.encaisse END), 0) as deja_paye,
             COALESCE(SUM(CASE WHEN t.facture_id IS NOT NULL AND t.montant_net - ROUND(t.encaisse - ROUND(t.montant_rembourse, 2), 2) > 0
                 THEN t.montant_net - ROUND(t.encaisse - ROUND(t.montant_rembourse, 2), 2) ELSE 0 END), 0) as total_a_encaisser',
            [...$annulees, ...$annulees, $cloturee, $cloturee],
        )->first();

        return [
            'totaux' => [
                'total_montant' => (float) $ligne->total_montant,
                'nb_total' => (int) $ligne->nb_total,
                'total_a_encaisser' => (float) $ligne->total_a_encaisser,
                'deja_paye' => (float) $ligne->deja_paye,
                'nb_cloturees' => (int) $ligne->nb_cloturees,
                'montant_cloturees' => (float) $ligne->montant_cloturees,
            ],
            'nb_resultats' => (int) $ligne->nb_resultats,
        ];
    }

    /**
     * Tri demandé (colonne connue) ou ordre par défaut (plus récentes d'abord) ; l'identifiant
     * départage les égalités pour que les pages restent stables.
     *
     * @param  Builder<CommandeVente>  $ventes
     */
    public static function trier(Builder $ventes, ?string $tri, string $sens): void
    {
        $sens = $sens === 'asc' ? 'asc' : 'desc';
        $ventes->reorder();

        match ($tri) {
            'reference' => $ventes->orderBy('commandes_ventes.reference', $sens),
            'date_remise_prevue_iso' => $ventes->orderBy('commandes_ventes.date_remise_prevue', $sens),
            'total_commande' => $ventes->orderBy('commandes_ventes.total_commande', $sens),
            'statut' => $ventes->orderBy('commandes_ventes.statut', $sens),
            'site_nom' => $ventes->orderBy(
                DB::table('sites')->select('nom')->whereColumn('sites.id', 'commandes_ventes.site_id')->whereNull('sites.deleted_at')->limit(1),
                $sens,
            ),
            'quantite_totale' => $ventes->orderBy(
                DB::table('commande_vente_lignes')
                    ->selectRaw('COALESCE(SUM(COALESCE(quantite_livree, quantite_chargee, quantite_demandee, 0)), 0)')
                    ->whereColumn('commande_vente_lignes.commande_vente_id', 'commandes_ventes.id'),
                $sens,
            ),
            // Net − encaissé net, sans le plancher à zéro : même ordre que le reste affiché, les
            // trop-perçus (affichés à 0) se rangeant simplement après les factures soldées.
            'facture_montant_restant' => $ventes->orderBy(
                DB::table('factures_ventes')
                    ->selectRaw('factures_ventes.montant_net - ROUND(('.self::sommeEncaissements()->toSql().') - ROUND(factures_ventes.montant_rembourse, 2), 2)')
                    ->whereColumn('factures_ventes.commande_vente_id', 'commandes_ventes.id')
                    ->whereNull('factures_ventes.deleted_at')
                    ->limit(1),
                $sens,
            ),
            'processus_label' => $ventes->orderByRaw(self::libelleProcessusSql().' '.$sens, self::libellesProcessus()),
            default => $ventes->orderBy('commandes_ventes.created_at', $tri === 'created_at' ? $sens : 'desc'),
        };

        $ventes->orderBy('commandes_ventes.id', $tri === null ? 'desc' : $sens);
    }

    /** Somme des encaissements d'une facture (sous-requête corrélée sur la colonne id de la facture). */
    private static function sommeEncaissements(string $colonneFacture = 'factures_ventes.id'): QueryBuilder
    {
        return DB::table('encaissements_ventes')
            ->selectRaw('COALESCE(SUM(encaissements_ventes.montant), 0)')
            ->whereColumn('encaissements_ventes.facture_vente_id', $colonneFacture);
    }

    /**
     * Libellé de processus calculé comme CommissionProcessusDefaults::identiteCodePourVente() :
     * nature client de la vente, sinon type du client (CommandeVente::natureClient()).
     */
    private static function libelleProcessusSql(): string
    {
        return "CASE
            WHEN commandes_ventes.nature_operation = '".NatureOperation::DISTRIBUTION_CLIENT->value."' THEN ?
            WHEN COALESCE(commandes_ventes.nature_client, (SELECT clients.type FROM clients WHERE clients.id = commandes_ventes.client_id AND clients.deleted_at IS NULL)) = '".ClientType::GROSSISTE->value."'
                AND commandes_ventes.mode_remise_grossiste = '".ModeRemiseGrossiste::LIVRAISON->value."' THEN ?
            ELSE ? END";
    }

    /** @return list<string> */
    private static function libellesProcessus(): array
    {
        return [
            CommissionProcessusDefaults::libelle(CommissionProcessus::CODE_DISTRIBUTION_CLIENT),
            CommissionProcessusDefaults::libelle(CommissionProcessus::CODE_TRANSFERT_GROSSISTE),
            CommissionProcessusDefaults::libelle(CommissionProcessus::CODE_VENTE),
        ];
    }
}
