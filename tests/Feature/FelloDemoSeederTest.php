<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ElmDemoOrganizationSeeder;
use Database\Seeders\FelloDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garanties attendues du seeder de démonstration "Fello Demo" (voir
 * database/seeders/FelloDemoSeeder.php) : isolation totale vis-à-vis de
 * l'organisation "elm" (Eau la maman), et idempotence.
 */
class FelloDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fello_demo_seeder_ne_modifie_pas_lorganisation_elm(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ElmDemoOrganizationSeeder::class);

        $elm = Organization::where('slug', 'elm')->firstOrFail();
        $elmUsersAvant = User::where('organization_id', $elm->id)->count();
        $elmSitesAvant = Site::where('organization_id', $elm->id)->count();

        $this->seed(FelloDemoSeeder::class);

        $this->assertSame(1, Organization::where('slug', 'elm')->count());
        $this->assertSame($elmUsersAvant, User::where('organization_id', $elm->id)->count());
        $this->assertSame($elmSitesAvant, Site::where('organization_id', $elm->id)->count());
    }

    public function test_fello_demo_seeder_cree_une_organisation_isolee_avec_ses_donnees(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(FelloDemoSeeder::class);

        $fello = Organization::where('slug', 'fello-demo')->firstOrFail();
        $this->assertSame('Fello Demo', $fello->name);

        $this->assertSame(2, Site::where('organization_id', $fello->id)->count());
        $this->assertSame(2, User::where('organization_id', $fello->id)->count());
        // 21 produits du catalogue de base + 1 "T-shirt Fello édition démo" (démonstration
        // options/variantes couleur × taille — cf. FelloDemoCatalogSeeder).
        $this->assertSame(22, Produit::where('organization_id', $fello->id)->count());
        $this->assertSame(5, Client::where('organization_id', $fello->id)->count());
        $this->assertGreaterThan(0, CommandeVente::where('organization_id', $fello->id)->count());
    }

    public function test_aucune_donnee_fello_demo_nest_rattachee_a_elm_et_inversement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ElmDemoOrganizationSeeder::class);
        $this->seed(FelloDemoSeeder::class);

        $elm = Organization::where('slug', 'elm')->firstOrFail();
        $fello = Organization::where('slug', 'fello-demo')->firstOrFail();

        foreach ([User::class, Site::class, Produit::class, Client::class, CommandeVente::class] as $model) {
            $feloIdsWithElmOrg = $model::whereIn('id', $model::where('organization_id', $fello->id)->pluck('id'))
                ->where('organization_id', $elm->id)
                ->count();
            $elmIdsWithFelloOrg = $model::whereIn('id', $model::where('organization_id', $elm->id)->pluck('id'))
                ->where('organization_id', $fello->id)
                ->count();

            $this->assertSame(0, $feloIdsWithElmOrg, "{$model} : fuite fello-demo -> elm");
            $this->assertSame(0, $elmIdsWithFelloOrg, "{$model} : fuite elm -> fello-demo");
        }
    }

    public function test_fello_demo_seeder_est_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(FelloDemoSeeder::class);
        $this->seed(FelloDemoSeeder::class);

        $fello = Organization::where('slug', 'fello-demo')->firstOrFail();

        $this->assertSame(1, Organization::where('slug', 'fello-demo')->count());
        $this->assertSame(2, Site::where('organization_id', $fello->id)->count());
        $this->assertSame(2, User::where('organization_id', $fello->id)->count());
        // 21 produits du catalogue de base + 1 "T-shirt Fello édition démo" (démonstration
        // options/variantes couleur × taille — cf. FelloDemoCatalogSeeder).
        $this->assertSame(22, Produit::where('organization_id', $fello->id)->count());
        $this->assertSame(5, Client::where('organization_id', $fello->id)->count());
    }
}
