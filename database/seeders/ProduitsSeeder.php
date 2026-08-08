<?php

namespace Database\Seeders;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use Illuminate\Database\Seeder;

class ProduitsSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        // Site par défaut pour le stock initial (avant refonte : qte_stock était un compteur
        // global sur Produit, migré vers un site au premier ajustement manuel — désormais
        // on l'attribue directement à un site réel dès le seed).
        $siteParDefaut = Site::where('organization_id', $org->id)->orderBy('created_at')->first();

        $produits = [
            [
                'nom' => 'Rouleau',
                'type' => ProduitType::MATERIEL->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_achat' => 300,
                'prix_vente' => 500,
                'qte_stock' => 500,
            ],
            [
                'nom' => 'Pack de 6 bouteilles',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 4100,
                'prix_vente' => 5000,
                'qte_stock' => 10000,
            ],
            [
                'nom' => 'Pack de 8 bouteilles',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 4500,
                'prix_vente' => 5000,
                'qte_stock' => 10000,
            ],
            [
                'nom' => 'Pack de 350ml',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 150000,
                'seuil_alerte_stock' => 1000,
                'description' => '15 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 1.500ml',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 22000,
                'prix_vente' => 25000,
                'qte_stock' => 25000,
                'seuil_alerte_stock' => 250,
                'description' => '6 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 500ml',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 100000,
                'seuil_alerte_stock' => 1000,
                'description' => '12 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 50ml',
                'type' => ProduitType::FABRICABLE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 900,
                'seuil_alerte_stock' => 100,
            ],
        ];

        $produitService = app(ProduitService::class);

        foreach ($produits as $data) {
            if (Produit::where('nom', $data['nom'])->where('organization_id', $org->id)->exists()) {
                continue;
            }

            $qteInitiale = $data['qte_stock'] ?? 0;
            unset($data['qte_stock']);

            $produit = $produitService->creer([...$data, 'organization_id' => $org->id]);

            if ($qteInitiale > 0 && $siteParDefaut) {
                $variante = $produit->variantePrincipale()->first();
                VarianteStock::create([
                    'organization_id' => $org->id,
                    'produit_variante_id' => $variante->id,
                    'site_id' => $siteParDefaut->id,
                    'qte_stock' => $qteInitiale,
                ]);
                $produit->resynchroniserQteStock();
            }
        }
    }
}
