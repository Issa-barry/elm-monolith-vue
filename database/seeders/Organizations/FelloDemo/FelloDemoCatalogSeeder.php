<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\CategorieStatut;
use App\Enums\ProduitStatut;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
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
        // Provisionné par ProduitTypeDefaultSeeder (cf. FelloDemoOrganizationSeeder) — revente,
        // pas de fabrication, nécessite prix_achat + prix_vente.
        $typeAchatVenteId = ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->value('id');
        $total = 0;

        // Catégories parentes créées par CategorieDefaultSeeder (cf. FelloDemoOrganizationSeeder)
        // — les sous-catégories vêtements se rattachent à "Vêtements" ; "Chaussures" a déjà sa
        // propre catégorie racine, pas besoin d'un niveau intermédiaire supplémentaire ici.
        $vetements = Categorie::where('organization_id', $org->id)->whereNull('parent_id')->where('nom', 'Vêtements')->first();
        $chaussuresParent = Categorie::where('organization_id', $org->id)->whereNull('parent_id')->where('nom', 'Chaussures')->first();

        foreach (self::PRODUITS as $nomCategorie => $items) {
            if ($nomCategorie === 'Chaussures' && $chaussuresParent) {
                $categorie = $chaussuresParent;
            } else {
                $categorie = Categorie::firstOrCreate(
                    ['organization_id' => $org->id, 'nom' => $nomCategorie, 'parent_id' => $vetements?->id],
                    ['statut' => CategorieStatut::ACTIF]
                );
            }

            foreach ($items as $item) {
                if (Produit::where('organization_id', $org->id)->where('nom', $item['nom'])->exists()) {
                    continue;
                }

                $produitService->creer([
                    'organization_id' => $org->id,
                    'categorie_id' => $categorie->id,
                    'nom' => $item['nom'],
                    'produit_type_id' => $typeAchatVenteId,
                    'statut' => ProduitStatut::ACTIF->value,
                    'prix_achat' => $item['achat'],
                    'prix_vente' => $item['vente'],
                    'alerte_stock_active' => true,
                    'seuil_alerte_stock' => 10,
                ]);
                $total++;
            }
        }

        // Démonstration du système d'options/variantes — persona réel qui a justifié le
        // chantier (catalogue habillement à déclinaisons couleur/taille).
        $categorieTshirts = Categorie::where('organization_id', $org->id)->where('nom', 'T-shirts')->first();
        $nomDemo = 'T-shirt Fello édition démo';
        // Produit::setNomAttribute() normalise la casse à l'enregistrement (majuscule en tête,
        // reste en minuscules) — comparer contre le nom brut ferait toujours échouer ce contrôle
        // d'idempotence pour "Fello" (créerait un doublon à chaque exécution du seeder).
        $nomDemoNormalise = (new Produit(['nom' => $nomDemo]))->nom;

        if ($categorieTshirts && ! Produit::where('organization_id', $org->id)->where('nom', $nomDemoNormalise)->exists()) {
            $produit = $produitService->creer([
                'organization_id' => $org->id,
                'categorie_id' => $categorieTshirts->id,
                'nom' => $nomDemo,
                'produit_type_id' => $typeAchatVenteId,
                'statut' => ProduitStatut::ACTIF->value,
                'prix_achat' => 40000,
                'prix_vente' => 75000,
                'alerte_stock_active' => true,
                'seuil_alerte_stock' => 5,
                'options' => [
                    [
                        'nom' => 'Couleur',
                        'valeurs' => ['Noir', 'Blanc'],
                        'option_catalogue_id' => OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Couleur')->value('id'),
                    ],
                    [
                        'nom' => 'Taille',
                        'valeurs' => ['S', 'M', 'L'],
                        'option_catalogue_id' => OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Taille')->value('id'),
                    ],
                ],
            ]);
            $total++;
            $this->command->info('  → '.$produit->variantes()->count()." variantes générées pour « {$nomDemo} » (démonstration options/variantes).");
        }

        $this->command->info("✓ {$total} produits créés, classés dans ".count(self::PRODUITS).' catégories réelles.');
    }
}
