<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Models\Categorie;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use Illuminate\Database\Seeder;

/**
 * Catégorie + produit unique, volontairement sans barème de commission ni
 * capacité véhicule pré-configurés : le parcours E2E cible configure ces
 * deux éléments lui-même via l'UI (Paramètres → Commissions, popup équipe),
 * c'est justement ce que le test doit vérifier.
 */
class ElmV2DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();
        // 'achat_vente' (pas 'materiel', qui est vendable=false par défaut, cf.
        // ProduitTypeDefaultSeeder) — un sachet d'eau vendu aux clients doit apparaître
        // dans CommandeVenteController::produitsActifs() (filtré sur vendable=true),
        // sans quoi le formulaire /ventes/create n'a aucun produit à proposer.
        $typeId = ProduitType::where('organization_id', $org->id)->where('code', 'achat_vente')->value('id');

        $categorie = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Sachets d\'eau V2 Demo'],
            ['statut' => 'actif']
        );

        $produit = Produit::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Sachet 0.5L V2 Demo'],
            ['produit_type_id' => $typeId, 'categorie_id' => $categorie->id, 'statut' => 'actif']
        );

        ProduitVariante::firstOrCreate(
            ['organization_id' => $org->id, 'produit_id' => $produit->id, 'is_default' => true],
            ['is_active' => true, 'prix_vente' => 2000, 'prix_usine' => 1500, 'prix_achat' => 1500, 'cout' => 1000]
        );

        $this->command->info('✓ Catégorie « Sachets d\'eau V2 Demo » + produit prêts (sans barème ni capacité — configurés par le test E2E).');
    }
}
