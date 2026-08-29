<?php

namespace App\Services;

use App\Models\Produit;
use App\Models\ProduitSeuilAlerte;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Gère les seuils d'alerte de stock spécifiques par SITE pour un produit — remplace l'ancien
 * seuil unique produits.seuil_alerte_stock (conservé en base à titre historique, plus jamais
 * écrit par le code applicatif). Absence de ligne pour un couple produit/site = repli sur
 * Parametre::getSeuilStockFaible() (cf. StockStatutService::seuilEffectifPourSite(), seule
 * lectrice de ces seuils pour le calcul de l'état de stock).
 */
class ProduitSeuilAlerteService
{
    /** @return Collection<string, int> seuil spécifique indexé par site_id */
    public function pourProduit(Produit $produit): Collection
    {
        return ProduitSeuilAlerte::where('produit_id', $produit->id)->pluck('seuil_alerte_stock', 'site_id');
    }

    /**
     * Définit (ou retire, si $seuil est null) le seuil spécifique d'un produit pour UN site.
     * Un champ vidé côté formulaire signifie « utiliser le seuil par défaut de l'organisation »,
     * jamais 0 — cf. règle métier ProduitForm.vue.
     */
    public function definir(Produit $produit, string $siteId, ?int $seuil): void
    {
        if ($seuil === null) {
            ProduitSeuilAlerte::where('produit_id', $produit->id)->where('site_id', $siteId)->delete();

            return;
        }

        ProduitSeuilAlerte::updateOrCreate(
            ['produit_id' => $produit->id, 'site_id' => $siteId],
            ['organization_id' => $produit->organization_id, 'seuil_alerte_stock' => $seuil],
        );
    }

    /**
     * Applique (ou retire) le même seuil sur TOUS les sites actifs de l'organisation — utilisé
     * uniquement par l'import en masse (ImportProduitsExecutor), qui ne connaît qu'une seule
     * valeur par produit dans le fichier Excel (préserve fonctionnellement le comportement
     * historique de l'ancienne colonne produits.seuil_alerte_stock : « s'applique à tous les
     * sites »). Jamais utilisé par le formulaire web, qui définit chaque site individuellement
     * via definir().
     */
    public function definirPourTousLesSitesActifs(Produit $produit, ?int $seuil): void
    {
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');

        foreach ($siteIds as $siteId) {
            $this->definir($produit, (string) $siteId, $seuil);
        }
    }

    /**
     * Valeur uniforme actuellement appliquée à TOUS les sites actifs de l'organisation pour ce
     * produit, ou null si aucun seuil spécifique identique n'est défini partout (mixte ou
     * absent) — utilisé UNIQUEMENT pour l'affichage synthétique de l'audit d'import (avant/
     * après), jamais pour le calcul réel de l'état de stock (cf. StockStatutService).
     */
    public function valeurUniformePourSitesActifs(Produit $produit): ?int
    {
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');
        if ($siteIds->isEmpty()) {
            return null;
        }

        $valeurs = ProduitSeuilAlerte::where('produit_id', $produit->id)
            ->whereIn('site_id', $siteIds)
            ->pluck('seuil_alerte_stock');

        if ($valeurs->count() !== $siteIds->count()) {
            return null;
        }

        return $valeurs->unique()->count() === 1 ? $valeurs->first() : null;
    }
}
