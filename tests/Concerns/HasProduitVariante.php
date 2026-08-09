<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitVariante;

/**
 * Depuis la refonte variant-first, Produit ne porte plus ni prix, ni SKU, ni stock — ces
 * champs vivent sur ProduitVariante (grain transactionnel réel, cf. produit_variantes).
 * Les tests qui créent un produit "simple" (sans déclinaison) doivent donc systématiquement
 * lui attacher une variante par défaut pour pouvoir vendre/acheter/transférer dessus.
 */
trait HasProduitVariante
{
    private function makeProduitAvecVariante(Organization $org, array $produitOverrides = [], array $varianteOverrides = []): Produit
    {
        $produit = Produit::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'Produit Test',
            'type' => 'materiel',
            'statut' => 'actif',
        ], $produitOverrides));

        $this->makeVariante($produit, $varianteOverrides);

        return $produit->fresh();
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
}
