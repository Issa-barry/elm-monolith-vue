<?php

namespace Database\Seeders;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Organization;
use App\Models\Produit;
use Illuminate\Database\Seeder;

class ProduitsSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm-demo')->firstOrFail();

        $produits = [
            [
                'nom'        => 'Rouleau',
                'type'       => ProduitType::MATERIEL->value,
                'statut'     => ProduitStatut::ACTIF->value,
                'prix_achat' => 300,
                'prix_vente' => 500,
                'qte_stock'  => 500,
            ],
            [
                'nom'        => 'Pack de 30',
                'type'       => ProduitType::FABRICABLE->value,
                'statut'     => ProduitStatut::ACTIF->value,
                'prix_usine' => 4500,
                'prix_vente' => 5000,
                'qte_stock'  => 200,
            ],
            [
                'nom'        => 'Pack de 6 bouteilles',
                'type'       => ProduitType::FABRICABLE->value,
                'statut'     => ProduitStatut::ACTIF->value,
                'prix_usine' => 4500,
                'prix_vente' => 5000,
                'qte_stock'  => 150,
            ],
        ];

        foreach ($produits as $data) {
            Produit::firstOrCreate(
                [
                    'nom'             => $data['nom'],
                    'organization_id' => $org->id,
                ],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
