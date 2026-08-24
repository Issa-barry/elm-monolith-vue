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
        // Stock initial ventilé sur CHAQUE site actif de l'org (avant refonte : qte_stock était
        // un compteur global sur Produit, migré vers un site au premier ajustement manuel —
        // désormais on l'attribue directement à des sites réels dès le seed). Ne provisionner
        // que le premier site créé laissait tous les autres sites à 0 : inoffensif tant que
        // MouvementStockService::appliquer() clampait silencieusement une sortie insuffisante à
        // 0, mais le correctif du 23/08/2026 (refus strict, plus de clamp) transforme ce 0 en
        // ValidationException dès qu'un scénario (transfert, vente...) part d'un autre site —
        // cf. global-setup.ts, dont le transfert échouait systématiquement pour cette raison.
        $sitesActifs = Site::where('organization_id', $org->id)
            ->where('statut', 'active')
            ->orderBy('created_at')
            ->get();

        // Types de produit — provisionnés par ProduitTypeDefaultSeeder (doit tourner avant ce
        // seeder, cf. DatabaseSeeder), résolus ici par leur code stable.
        $typeIds = ProduitType::where('organization_id', $org->id)
            ->whereIn('code', ['materiel', 'fabricable'])
            ->pluck('id', 'code');

        // Deux catégories seulement (décision produit du 15/08/2026) : "elm" ne vend que de
        // l'eau en sachet et en bouteille — c'est aussi ce qui permet à VehiculeCapaciteService
        // d'appliquer un plafond de chargement indépendant par famille (cf.
        // vehicule_capacites/type_vehicule_capacites), au lieu d'un seul plafond global qui
        // mélangeait les deux. Pas de hiérarchie "Boissons" parente : inutile tant qu'il n'y a
        // que ces deux familles.
        // Références explicites (plutôt que le slug auto-généré du nom) : ce sont les deux
        // catégories de référence ciblées par l'import flotte (colonnes
        // vehicule_capacite_sachets/vehicule_capacite_bouteilles, cf.
        // ImportFlotteParser::CATEGORIE_SACHETS_REFERENCE/CATEGORIE_BOUTEILLES_REFERENCE) — une
        // référence stable et connue à l'avance, indépendante du libellé (renommable sans casser
        // l'import).
        $sachet = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => "Sachet d'eau", 'parent_id' => null],
            ['statut' => CategorieStatut::ACTIF, 'reference' => 'SACHET_EAU']
        );
        $bouteille = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => "Bouteille d'eau", 'parent_id' => null],
            ['statut' => CategorieStatut::ACTIF, 'reference' => 'BOUTEILLE_EAU']
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
                'prix_usine_tricycle' => 4100,
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
                'prix_usine_tricycle' => 4500,
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
                'prix_usine_tricycle' => 18000,
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
                'prix_usine_tricycle' => 22000,
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
                'prix_usine_tricycle' => 18000,
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
                'prix_usine_tricycle' => 18000,
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

            if ($qteInitiale > 0 && $variante) {
                foreach ($sitesActifs as $site) {
                    VarianteStock::create([
                        'organization_id' => $org->id,
                        'produit_variante_id' => $variante->id,
                        'site_id' => $site->id,
                        'qte_stock' => $qteInitiale,
                    ]);
                }
                $produit->resynchroniserQteStock();
            }
        }
    }
}
