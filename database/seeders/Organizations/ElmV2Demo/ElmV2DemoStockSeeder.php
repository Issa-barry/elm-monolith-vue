<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Enums\MotifAjustementStock;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Produit;
use App\Models\Site;
use App\Models\UserAuthIdentity;
use App\Models\VarianteStock;
use Illuminate\Database\Seeder;

/**
 * Stock initial suffisant pour créer plusieurs commandes de vente lors du
 * parcours E2E cible (chaque exécution consomme une petite quantité).
 */
class ElmV2DemoStockSeeder extends Seeder
{
    private const QUANTITE = 10000;

    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();
        $site = Site::where('organization_id', $org->id)->where('nom', 'Siège V2 Demo')->firstOrFail();
        $produit = Produit::where('organization_id', $org->id)->where('nom', 'Sachet 0.5L V2 Demo')->firstOrFail();
        $variante = $produit->variantePrincipale()->firstOrFail();

        $admin = UserAuthIdentity::resoudre(
            UserAuthIdentity::TYPE_TELEPHONE,
            Personne::normaliserTelephone(ElmV2DemoUsersSeeder::TELEPHONE)
        );
        if (! $admin || $admin->organization_id !== $org->id) {
            throw new \RuntimeException('Admin de démo introuvable ('.ElmV2DemoUsersSeeder::TELEPHONE.') pour elm-v2-demo.');
        }

        $stock = VarianteStock::updateOrCreate(
            ['produit_variante_id' => $variante->id, 'site_id' => $site->id],
            ['organization_id' => $org->id, 'qte_stock' => self::QUANTITE]
        );

        $dejaTrace = MouvementStock::where('source_type', VarianteStock::class)
            ->where('source_id', $stock->id)
            ->where('type', 'entree')
            ->exists();

        if (! $dejaTrace) {
            MouvementStock::create([
                'organization_id' => $org->id,
                'site_id' => $site->id,
                'produit_variante_id' => $variante->id,
                'type' => 'entree',
                'quantite' => self::QUANTITE,
                'stock_avant' => 0,
                'stock_apres' => self::QUANTITE,
                'source_type' => VarianteStock::class,
                'source_id' => $stock->id,
                'notes' => MotifAjustementStock::AUTRE->toNotesString('Stock initial démo V2'),
                'created_by' => $admin->id,
            ]);
        }

        $produit->resynchroniserQteStock();

        $this->command->info('✓ Stock initial ('.self::QUANTITE.' unités) prêt sur Siège V2 Demo.');
    }
}
