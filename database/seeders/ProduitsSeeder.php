<?php

namespace Database\Seeders;

use App\Enums\CategorieStatut;
use App\Enums\ProduitStatut;
use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
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

        // Types de produit — provisionnés par ProduitTypeDefaultSeeder (doit tourner avant ce
        // seeder, cf. DatabaseSeeder/ProductionSeeder), résolus ici par leur code stable.
        $typeIds = ProduitType::where('organization_id', $org->id)
            ->whereIn('code', ['materiel', 'fabricable'])
            ->pluck('id', 'code');

        // Deux catégories seulement (décision produit du 15/08/2026) : "elm" ne vend que de
        // l'eau en sachet et en bouteille — c'est aussi ce qui permet à VehiculeCapaciteService
        // d'appliquer un plafond de chargement indépendant par famille (cf.
        // vehicule_capacites/type_vehicule_capacites), au lieu d'un seul plafond global qui
        // mélangeait les deux. Pas de hiérarchie "Boissons" parente : inutile tant qu'il n'y a
        // que ces deux familles.
        $sachet = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Sachet', 'parent_id' => null],
            ['statut' => CategorieStatut::ACTIF]
        );
        $bouteille = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Bouteille', 'parent_id' => null],
            ['statut' => CategorieStatut::ACTIF]
        );

        // categorie_id reste null pour "Rouleau" : c'est un consommable d'usine (matériel),
        // jamais chargé sur un véhicule de livraison — non concerné par le contrôle de capacité.
        $produits = [
            [
                'nom' => 'Rouleau',
                'produit_type_id' => $typeIds['materiel'],
                'statut' => ProduitStatut::ACTIF->value,
                'prix_achat' => 300,
                'prix_vente' => 500,
                'qte_stock' => 500,
                'alerte_stock_active' => false,
            ],
            [
                'nom' => 'Pack de 6 bouteilles',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $bouteille->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 4100,
                'prix_vente' => 5000,
                'qte_stock' => 10000,
                'alerte_stock_active' => false,
            ],
            [
                'nom' => 'Pack de 8 bouteilles',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $bouteille->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 4500,
                'prix_vente' => 5000,
                'qte_stock' => 10000,
                'alerte_stock_active' => false,
            ],
            [
                'nom' => 'Pack de 350ml',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $sachet->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 150000,
                'alerte_stock_active' => true,
                'seuil_alerte_stock' => 1000,
                'description' => '15 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 1.500ml',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $sachet->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 22000,
                'prix_vente' => 25000,
                'qte_stock' => 25000,
                'alerte_stock_active' => true,
                'seuil_alerte_stock' => 250,
                'description' => '6 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 500ml',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $sachet->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 100000,
                'alerte_stock_active' => true,
                'seuil_alerte_stock' => 1000,
                'description' => '12 bouteilles par packs',
            ],
            [
                'nom' => 'Packs de 50ml',
                'produit_type_id' => $typeIds['fabricable'],
                'categorie_id' => $sachet->id,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_usine' => 18000,
                'prix_vente' => 20000,
                'qte_stock' => 900,
                'alerte_stock_active' => true,
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

            // Référence (sku) laissée vide : générée automatiquement par
            // Organization::prochaineReferenceProduit() via ProduitVariante::booted(),
            // exactement comme pour un produit créé depuis l'interface.
            $produit = $produitService->creer([...$data, 'organization_id' => $org->id]);
            $variante = $produit->variantePrincipale()->first();

            if ($qteInitiale > 0 && $siteParDefaut && $variante) {
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
