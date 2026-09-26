<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertStatus(200);
    }

    // ── Périmètre d'agence (SiteScopeService, cf. docs/rapports.md) ──────────

    private function facture(Organization $org, Site $site, float $montant, string $statut = 'impayee'): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'total_commande' => $montant,
        ]);

        return FactureVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
    }

    /** @return array{0: Organization, 1: Site, 2: Site} */
    private function deuxAgences(): array
    {
        $org = Organization::factory()->create();
        $matoto = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'agence', 'localisation' => 'Conakry']);
        $kouria = Site::create(['organization_id' => $org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->facture($org, $matoto, 100_000);
        $this->facture($org, $kouria, 900_000, 'payee');

        return [$org, $matoto, $kouria];
    }

    /** @return array<string, mixed> */
    private function props(User $user): array
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
    }

    public function test_un_utilisateur_non_admin_ne_voit_que_les_chiffres_de_ses_agences(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('manager');
        $manager->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->props($manager);

        $this->assertSame(1, $props['stats_factures']['total_count']);
        $this->assertEquals(100_000, $props['stats_factures']['total_montant']);
        $this->assertEquals(0, $props['stats_factures']['payees_montant']);
        $this->assertSame(['Matoto'], array_column($props['ca_par_site'], 'nom'));
        $this->assertEquals(100_000, array_sum(array_column($props['evolution_quotidienne'], 'impayees')));
        $this->assertEquals(0, array_sum(array_column($props['evolution_quotidienne'], 'payees')));
        $this->assertSame(['Matoto'], $props['agences']);
    }

    public function test_un_administrateur_voit_toute_l_organisation(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->props($admin);

        $this->assertSame(2, $props['stats_factures']['total_count']);
        $this->assertEquals(1_000_000, $props['stats_factures']['total_montant']);
        $this->assertEqualsCanonicalizing(['Matoto', 'Kouria'], array_column($props['ca_par_site'], 'nom'));
        $this->assertNull($props['agences']);
    }
}
