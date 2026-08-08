<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Organization;
use App\Models\Produit;
use Illuminate\Database\Seeder;

/**
 * Catalogue de démonstration (vêtements/accessoires), type ACHAT_VENTE
 * (revente, pas de fabrication — nécessite prix_achat + prix_vente).
 *
 * Le projet n'a PAS de modèle/colonne "catégorie" pour Produit (vérifié :
 * aucune table categories, aucune colonne categorie sur produits — seule
 * Vehicule a une colonne categorie, sans rapport). La catégorie demandée
 * dans le scénario est donc indiquée dans `description`, faute de mieux,
 * plutôt que d'inventer un champ qui n'existe pas dans le schéma réel.
 */
class FelloDemoCatalogSeeder extends Seeder
{
    private const PRODUITS = [
        'T-shirts' => [
            ['nom' => 'T-shirt coton homme', 'achat' => 45000, 'vente' => 80000],
            ['nom' => 'T-shirt premium femme', 'achat' => 60000, 'vente' => 110000],
            ['nom' => 'T-shirt manches courtes enfant', 'achat' => 30000, 'vente' => 85000],
        ],
        'Chemises' => [
            ['nom' => 'Chemise manches longues', 'achat' => 90000, 'vente' => 160000],
            ['nom' => 'Chemise wax', 'achat' => 110000, 'vente' => 190000],
            ['nom' => 'Chemise slim fit homme', 'achat' => 95000, 'vente' => 170000],
        ],
        'Pantalons' => [
            ['nom' => 'Jean slim homme', 'achat' => 120000, 'vente' => 220000],
            ['nom' => 'Pantalon classique femme', 'achat' => 100000, 'vente' => 180000],
            ['nom' => 'Short bermuda homme', 'achat' => 75000, 'vente' => 135000],
        ],
        'Robes' => [
            ['nom' => 'Robe wax courte', 'achat' => 130000, 'vente' => 240000],
            ['nom' => 'Robe longue élégante', 'achat' => 180000, 'vente' => 320000],
            ['nom' => 'Ensemble femme', 'achat' => 200000, 'vente' => 350000],
        ],
        'Chaussures' => [
            ['nom' => 'Sneakers homme', 'achat' => 280000, 'vente' => 480000],
            ['nom' => 'Sandales femme', 'achat' => 190000, 'vente' => 330000],
            ['nom' => 'Basket running femme', 'achat' => 220000, 'vente' => 390000],
        ],
        'Accessoires' => [
            ['nom' => 'Ceinture cuir', 'achat' => 35000, 'vente' => 65000],
            ['nom' => 'Sac à main', 'achat' => 80000, 'vente' => 145000],
            ['nom' => 'Casquette', 'achat' => 25000, 'vente' => 45000],
            ['nom' => 'Foulard', 'achat' => 20000, 'vente' => 38000],
            ['nom' => 'Chaussettes lot de 3', 'achat' => 15000, 'vente' => 28000],
            ['nom' => 'Montre bracelet homme', 'achat' => 70000, 'vente' => 130000],
        ],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();
        $total = 0;

        foreach (self::PRODUITS as $categorie => $items) {
            foreach ($items as $item) {
                Produit::updateOrCreate(
                    ['organization_id' => $org->id, 'nom' => $item['nom']],
                    [
                        'type' => ProduitType::ACHAT_VENTE,
                        'statut' => ProduitStatut::ACTIF,
                        'prix_achat' => $item['achat'],
                        'prix_vente' => $item['vente'],
                        'description' => "Catégorie : {$categorie}",
                        'seuil_alerte_stock' => 10,
                    ]
                );
                $total++;
            }
        }

        $nbCategories = count(self::PRODUITS);
        $this->command->info("✓ {$total} produits créés ({$nbCategories} catégories, indiquées dans la description — pas de modèle Categorie dans le projet).");
    }
}
