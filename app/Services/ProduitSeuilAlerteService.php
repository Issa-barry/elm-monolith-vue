<?php

namespace App\Services;

use App\Models\Produit;
use App\Models\ProduitSeuilAlerte;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Gère la configuration de l'alerte de stock faible (actif + seuil spécifique) par SITE pour un
 * produit — remplace l'ancien choix global produits.alerte_stock_active et l'ancien seuil unique
 * produits.seuil_alerte_stock (les deux conservés en base à titre historique, plus jamais écrits
 * par le code applicatif). Absence de ligne pour un couple produit/site = alerte INACTIVE sur ce
 * site (cf. StockStatutService::alerteActivePourSite(), seule lectrice avec
 * seuilEffectifPourSite() pour le calcul de l'état de stock).
 */
class ProduitSeuilAlerteService
{
    /** @return Collection<string, array{actif: bool, seuil: int|null}> config indexée par site_id */
    public function pourProduit(Produit $produit): Collection
    {
        return ProduitSeuilAlerte::where('produit_id', $produit->id)
            ->get()
            ->mapWithKeys(fn (ProduitSeuilAlerte $p) => [
                (string) $p->site_id => ['actif' => $p->actif, 'seuil' => $p->seuil_alerte_stock],
            ]);
    }

    /**
     * Définit la configuration d'un produit pour UN site. Activer ($actif = true) crée/modifie la
     * ligne avec le seuil fourni (null = repli sur le seuil par défaut de l'organisation, jamais
     * une erreur bloquante — cf. règle métier ProduitForm.vue). Désactiver ($actif = false) ne
     * supprime jamais un seuil spécifique déjà enregistré : la ligne est simplement marquée
     * inactive (ou rien n'est créé si elle n'existait pas), pour ne pas perdre la configuration en
     * cas de réactivation ultérieure du site sur ce produit.
     */
    public function definir(Produit $produit, string $siteId, bool $actif, ?int $seuil): void
    {
        if (! $actif) {
            ProduitSeuilAlerte::where('produit_id', $produit->id)
                ->where('site_id', $siteId)
                ->update(['actif' => false]);

            return;
        }

        ProduitSeuilAlerte::updateOrCreate(
            ['produit_id' => $produit->id, 'site_id' => $siteId],
            ['organization_id' => $produit->organization_id, 'actif' => true, 'seuil_alerte_stock' => $seuil],
        );
    }

    /**
     * Applique la même configuration (actif + seuil) sur TOUS les sites actifs de l'organisation
     * — utilisé uniquement par l'import en masse (ImportProduitsExecutor), qui ne connaît qu'une
     * seule valeur par produit dans le fichier Excel (préserve fonctionnellement le comportement
     * historique de l'ancien couple de colonnes produits.alerte_stock_active/seuil_alerte_stock :
     * « s'applique à tous les sites »). Jamais utilisé par le formulaire web, qui définit chaque
     * site individuellement via definir().
     */
    public function definirPourTousLesSitesActifs(Produit $produit, bool $actif, ?int $seuil): void
    {
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');

        foreach ($siteIds as $siteId) {
            $this->definir($produit, (string) $siteId, $actif, $seuil);
        }
    }

    /**
     * Active/désactive l'alerte sur TOUS les sites actifs SANS toucher au seuil déjà enregistré
     * sur chacun (contrairement à definirPourTousLesSitesActifs(), qui écrase uniformément le
     * seuil) — utilisé par l'import en masse quand une ligne de mise à jour ne renseigne QUE la
     * colonne alerte_stock_active (seuil_alerte_stock absente de la ligne), pour ne jamais
     * effacer un seuil spécifique par site déjà configuré depuis le formulaire web.
     */
    public function activerPourTousLesSitesActifs(Produit $produit, bool $actif): void
    {
        $seuilsExistants = $this->pourProduit($produit);
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');

        foreach ($siteIds as $siteId) {
            $this->definir($produit, (string) $siteId, $actif, $seuilsExistants->get((string) $siteId)['seuil'] ?? null);
        }
    }

    /**
     * Met à jour le SEUIL sur les sites actifs qui ont déjà une ligne, SANS toucher à leur
     * activation ni en créer de nouvelle (contrairement à definirPourTousLesSitesActifs()) —
     * utilisé par l'import en masse quand une ligne de mise à jour ne renseigne QUE la colonne
     * seuil_alerte_stock (alerte_stock_active absente de la ligne) : un seuil seul, sans
     * activation déjà explicite pour ce site, n'a aucun effet.
     */
    public function definirSeuilSeulPourTousLesSitesActifs(Produit $produit, ?int $seuil): void
    {
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');

        ProduitSeuilAlerte::where('produit_id', $produit->id)
            ->whereIn('site_id', $siteIds)
            ->update(['seuil_alerte_stock' => $seuil]);
    }

    /**
     * Seuil uniforme actuellement appliqué à TOUS les sites actifs et actifs pour l'alerte de
     * l'organisation pour ce produit, ou null si aucune valeur identique n'est partagée par tous
     * (mixte, absent, ou au moins un site non actif pour l'alerte) — utilisé UNIQUEMENT pour
     * l'affichage synthétique de l'audit d'import (avant/après), jamais pour le calcul réel de
     * l'état de stock (cf. StockStatutService).
     */
    public function valeurUniformePourSitesActifs(Produit $produit): ?int
    {
        $siteIds = Site::where('organization_id', $produit->organization_id)->actives()->pluck('id');
        if ($siteIds->isEmpty()) {
            return null;
        }

        $valeurs = ProduitSeuilAlerte::where('produit_id', $produit->id)
            ->where('actif', true)
            ->whereIn('site_id', $siteIds)
            ->pluck('seuil_alerte_stock', 'site_id');

        if ($valeurs->count() !== $siteIds->count()) {
            return null;
        }

        $uniques = $valeurs->values()->unique();

        return $uniques->count() === 1 ? $uniques->first() : null;
    }
}
