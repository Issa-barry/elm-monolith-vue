<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use Database\Seeders\ProduitTypeDefaultSeeder;

/**
 * Depuis la refonte variant-first, Produit ne porte plus ni prix, ni SKU, ni stock — ces
 * champs vivent sur ProduitVariante (grain transactionnel réel, cf. produit_variantes).
 * Les tests qui créent un produit "simple" (sans déclinaison) doivent donc systématiquement
 * lui attacher une variante par défaut pour pouvoir vendre/acheter/transférer dessus.
 *
 * Depuis la refonte CRUD des types (remplace l'ancien enum figé App\Enums\ProduitType),
 * `type` n'est plus une string mais `produit_type_id` (FK) — ce trait accepte toujours le
 * raccourci `'type' => 'materiel'|'service'|'fabricable'|'achat_vente'` dans $produitOverrides
 * pour ne pas casser tous les appelants existants, et le résout en id via le code stable.
 */
trait HasProduitVariante
{
    private function makeProduitAvecVariante(Organization $org, array $produitOverrides = [], array $varianteOverrides = []): Produit
    {
        if (array_key_exists('type', $produitOverrides)) {
            $produitOverrides['produit_type_id'] = $this->produitTypeId($org, $produitOverrides['type']);
            unset($produitOverrides['type']);
        }
        // is_alerte n'existe plus (checkbox manuelle supprimée, état désormais calculé).
        unset($produitOverrides['is_alerte']);

        $produit = Produit::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'Produit Test',
            'produit_type_id' => $this->produitTypeId($org, 'materiel'),
            'statut' => 'actif',
        ], $produitOverrides));

        $this->makeVariante($produit, $varianteOverrides);

        return $produit->fresh();
    }

    /**
     * Résout l'id du type par défaut (code stable) pour une organisation — le provisionne
     * au passage si besoin (idempotent, cf. ProduitTypeDefaultSeeder::seedPourOrganisation()).
     */
    private function produitTypeId(Organization $org, string $code): string
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);

        return ProduitType::where('organization_id', $org->id)->where('code', $code)->value('id');
    }

    private function makeVariante(Produit $produit, array $overrides = []): ProduitVariante
    {
        return ProduitVariante::create(array_merge([
            'organization_id' => $produit->organization_id,
            'produit_id' => $produit->id,
            'is_default' => true,
            'is_active' => true,
            'prix_vente' => 2000,
            'prix_usine' => 1500,
            'prix_achat' => 1500,
            'cout' => 1000,
        ], $overrides));
    }

    /**
     * Seed un stock largement suffisant pour une variante sur un site — depuis le correctif du
     * 23/08/2026 (suppression du clamp silencieux dans MouvementStockService::appliquer()), tout
     * test qui fait progresser une CommandeVente jusqu'à validerChargement() ou un
     * TransfertLogistique jusqu'à TRANSIT, pour un produit gere_stock=true, DOIT disposer d'un
     * stock suffisant sur le site concerné — sinon la sortie est désormais refusée
     * (ValidationException) au lieu d'être clampée à 0 comme avant. updateOrCreate() : idempotent
     * vis-à-vis de la contrainte unique (produit_variante_id, site_id), rejouable sans conflit si
     * un test a déjà créé la ligne avec une autre quantité.
     */
    private function seedVarianteStockSuffisant(ProduitVariante $variante, Site $site, int $qte = 100000): VarianteStock
    {
        return VarianteStock::updateOrCreate(
            ['produit_variante_id' => $variante->id, 'site_id' => $site->id],
            ['organization_id' => $variante->organization_id, 'qte_stock' => $qte],
        );
    }
}
