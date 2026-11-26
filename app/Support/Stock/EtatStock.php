<?php

namespace App\Support\Stock;

use App\Enums\ProduitStatut;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * État actuel du stock par VARIANTE × SITE (docs/stock-alertes.md), partagé par la page Stock
 * (IndexStockController) et le tableau de bord Stat-Stock (TableauDeBordStockService) : un seul
 * calcul des quantités physique / réservée / disponible, du seuil effectif, de la disponibilité et
 * de l'alerte par site, et un seul périmètre d'agences consultables.
 */
final class EtatStock
{
    /** Stock physique du couple (variante, site) ; 0 sans ligne variante_stocks. */
    public const PHYSIQUE = 'COALESCE(vs.qte_stock, 0)';

    /** Quantité réservée par les commandes vente confirmées, pas encore chargées. */
    public const RESERVE = 'COALESCE(vs.qte_reservee, 0)';

    /**
     * « Disponible » = ce qui reste réellement vendable — physique moins réservé (commandes vente
     * confirmées, pas encore chargées, cf. StockReservationService). L'état se base sur CETTE
     * quantité, jamais le physique brut : un stock physique positif mais entièrement réservé doit
     * apparaître en Rupture, pas Disponible (24/08/2026, même bug que la page Commande CMD-* :
     * deux commandes pouvaient toutes deux promettre le même stock tant que rien n'était retenu
     * entre la confirmation et le chargement).
     */
    public const QUANTITE = '('.self::PHYSIQUE.' - '.self::RESERVE.')';

    /**
     * Seuil résolu PAR SITE (produit_seuils_alerte, cf. StockStatutService::
     * seuilEffectifPourSite()) — jamais l'ancienne colonne p.seuil_alerte_stock (unique, conservée
     * en base à titre historique) : le seuil de Matoto ne doit jamais servir à contrôler le stock
     * de CBA. Le `?` reçoit le seuil global de l'organisation.
     */
    public const SEUIL = 'COALESCE(psa.seuil_alerte_stock, ?)';

    /**
     * DISPONIBILITÉ résolue PAR SITE (cf. StockStatutService::disponiblePourSite()) — défaut VRAI
     * (absence de ligne psa = disponible partout, mode "Tous les sites") : un site non disponible
     * n'a aucune rupture "métier" possible, quel que soit son stock physique.
     */
    public const DISPONIBLE = 'COALESCE(psa.disponible, 1)';

    /**
     * ALERTE résolue PAR SITE (cf. StockStatutService::alerteActivePourSite()) — défaut FAUX,
     * jamais implicite. N'intervient jamais dans le statut lui-même : seuls les badges, compteurs
     * et notifications la combinent à la disponibilité.
     */
    public const ALERTE_ACTIVE = 'COALESCE(psa.actif, 0)';

    /**
     * Une ligne par couple (variante active d'un produit non archivé dont le type gère le stock,
     * site du périmètre), même sans ligne variante_stocks (quantités à 0).
     *
     * @param  list<string>  $siteIds
     */
    public static function requete(string $orgId, array $siteIds, int $seuilOrganisation): Builder
    {
        return DB::table('produit_variantes as pv')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->join('sites as s', function ($join) use ($orgId, $siteIds) {
                $join->where('s.organization_id', '=', $orgId)
                    ->whereNull('s.deleted_at')
                    ->whereIn('s.id', $siteIds);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.categorie_id')
            ->leftJoin('variante_stocks as vs', function ($join) {
                $join->on('vs.produit_variante_id', '=', 'pv.id')
                    ->on('vs.site_id', '=', 's.id');
            })
            ->leftJoin('produit_seuils_alerte as psa', function ($join) {
                $join->on('psa.produit_id', '=', 'p.id')
                    ->on('psa.site_id', '=', 's.id');
            })
            ->where('p.organization_id', $orgId)
            ->where('pv.organization_id', $orgId)
            ->where('pt.organization_id', $orgId)
            ->where('pt.gere_stock', true)
            ->where('p.statut', '!=', ProduitStatut::ARCHIVE->value)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select([
                'p.id as produit_id',
                'p.nom as produit_nom',
                'p.categorie_id',
                'c.nom as categorie_nom',
                'pv.id as variante_id',
                'pv.sku',
                'pv.is_default',
                'pv.combo_hash',
                's.id as site_id',
                's.nom as site_nom',
                's.code as site_code',
            ])
            ->selectRaw(self::QUANTITE.' as qte_disponible')
            ->selectRaw(self::PHYSIQUE.' as qte_physique')
            ->selectRaw(self::RESERVE.' as qte_reservee')
            ->selectRaw(self::SEUIL.' as seuil_effectif', [$seuilOrganisation])
            ->selectRaw(self::DISPONIBLE.' as disponible_sur_site')
            ->selectRaw(self::ALERTE_ACTIVE.' as alerte_active');
    }

    /**
     * Agences dont l'utilisateur consulte le stock : toutes celles de l'organisation s'il voit
     * toutes les agences, sinon ses agences de rattachement.
     *
     * @return Collection<int, Site>
     */
    public static function sitesConsultables(User $user, string $orgId): Collection
    {
        $query = Site::where('organization_id', $orgId)->orderBy('nom');

        if (! $user->voitToutesLesAgences()) {
            $query->whereIn('id', $user->sites()->where('sites.organization_id', $orgId)->pluck('sites.id'));
        }

        return $query->get(['id', 'nom', 'code']);
    }

    /** « Variante par défaut », ou les valeurs d'options de la variante (« 1,5 L / Pack de 6 »). */
    public static function libelleVariante(?ProduitVariante $variante): string
    {
        if (! $variante || $variante->combo_hash === ProduitVariante::COMBO_HASH_DEFAUT) {
            return 'Variante par défaut';
        }

        $libelle = $variante->valeurs
            ->sort(fn ($a, $b) => [
                $a->option?->position ?? 0,
                $a->position,
            ] <=> [
                $b->option?->position ?? 0,
                $b->position,
            ])
            ->pluck('valeur')
            ->implode(' / ');

        return $libelle !== '' ? $libelle : 'Variante';
    }
}
