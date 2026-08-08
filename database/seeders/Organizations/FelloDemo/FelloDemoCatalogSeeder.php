<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\CategorieStatut;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Produit;
use App\Services\ProduitService;
use Illuminate\Database\Seeder;

/**
 * Catalogue de démonstration (vêtements/accessoires), type ACHAT_VENTE
 * (revente, pas de fabrication — nécessite prix_achat + prix_vente).
 *
 * Depuis la refonte variant-first : les catégories sont de vraies lignes `categories`
 * (avant refonte, elles étaient indiquées dans `description`, faute de modèle Categorie —
 * cf. historique git). Un produit ("T-shirt Fello édition démo") illustre en plus le système
 * d'options/variantes (Couleur × Taille) — c'est le persona qui a justifié le chantier
 * variantes : ce n'est pas qu'un besoin spéculatif du tenant de démo.
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
        $produitService = app(ProduitService::class);
        $total = 0;

        foreach (self::PRODUITS as $nomCategorie => $items) {
            $categorie = Categorie::firstOrCreate(
                ['organization_id' => $org->id, 'nom' => $nomCategorie],
                ['statut' => CategorieStatut::ACTIF]
            );

            foreach ($items as $item) {
                if (Produit::where('organization_id', $org->id)->where('nom', $item['nom'])->exists()) {
                    continue;
                }

                $produitService->creer([
                    'organization_id' => $org->id,
                    'categorie_id' => $categorie->id,
                    'nom' => $item['nom'],
                    'type' => ProduitType::ACHAT_VENTE->value,
                    'statut' => ProduitStatut::ACTIF->value,
                    'prix_achat' => $item['achat'],
                    'prix_vente' => $item['vente'],
                    'seuil_alerte_stock' => 10,
                ]);
                $total++;
            }
        }

        // Démonstration du système d'options/variantes — persona réel qui a justifié le
        // chantier (catalogue habillement à déclinaisons couleur/taille).
        $categorieTshirts = Categorie::where('organization_id', $org->id)->where('nom', 'T-shirts')->first();
        $nomDemo = 'T-shirt Fello édition démo';

        if ($categorieTshirts && ! Produit::where('organization_id', $org->id)->where('nom', $nomDemo)->exists()) {
            $produit = $produitService->creer([
                'organization_id' => $org->id,
                'categorie_id' => $categorieTshirts->id,
                'nom' => $nomDemo,
                'type' => ProduitType::ACHAT_VENTE->value,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_achat' => 40000,
                'prix_vente' => 75000,
                'seuil_alerte_stock' => 5,
                'options' => [
                    ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
                    ['nom' => 'Taille', 'valeurs' => ['S', 'M', 'L']],
                ],
            ]);
            $total++;
            $this->command->info('  → '.$produit->variantes()->count()." variantes générées pour « {$nomDemo} » (démonstration options/variantes).");
        }

        $this->command->info("✓ {$total} produits créés, classés dans ".count(self::PRODUITS).' catégories réelles.');
    }
}
