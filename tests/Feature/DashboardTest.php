<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Models\Client;
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

    // ── Ventes par processus (identité résolue comme la liste des ventes) ────

    private function factureProcessus(Organization $org, Site $site, float $montant, array $commande, ?ClientType $clientType = null, string $statut = 'impayee'): void
    {
        $client = $clientType
            ? Client::factory()->create(['organization_id' => $org->id, 'type' => $clientType->value])
            : null;

        $cmd = CommandeVente::factory()->create(array_merge([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'client_id' => $client?->id,
            'total_commande' => $montant,
        ], $commande));

        FactureVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $cmd->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
    }

    public function test_le_ca_est_reparti_par_processus_de_vente_hors_factures_annulees(): void
    {
        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'agence', 'localisation' => 'Conakry']);

        $this->factureProcessus($org, $site, 100_000, ['nature_operation' => NatureOperation::VENTE_STANDARD->value], ClientType::REVENDEUR);
        $this->factureProcessus($org, $site, 50_000, ['nature_operation' => NatureOperation::VENTE_STANDARD->value]);
        // Grossiste en enlèvement : reste une Vente (décision produit du 05/09/2026).
        $this->factureProcessus($org, $site, 30_000, ['mode_remise_grossiste' => ModeRemiseGrossiste::ENLEVEMENT->value], ClientType::GROSSISTE);
        $this->factureProcessus($org, $site, 400_000, ['mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value], ClientType::GROSSISTE);
        $this->factureProcessus($org, $site, 200_000, ['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value], ClientType::REVENDEUR);
        $this->factureProcessus($org, $site, 999_000, ['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value], ClientType::REVENDEUR, 'annulee');

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $parCode = collect($this->props($admin)['ca_par_processus'])->keyBy('code');

        $this->assertSame(['vente', 'distribution_client', 'transfert_grossiste'], $parCode->keys()->all());
        $this->assertEquals(180_000, $parCode['vente']['montant']);
        $this->assertSame(3, $parCode['vente']['nb_factures']);
        $this->assertSame('Vente', $parCode['vente']['label']);
        $this->assertEquals(200_000, $parCode['distribution_client']['montant']);
        $this->assertSame(1, $parCode['distribution_client']['nb_factures']);
        $this->assertEquals(400_000, $parCode['transfert_grossiste']['montant']);
        $this->assertSame('Transfert grossiste', $parCode['transfert_grossiste']['label']);
    }

    public function test_le_ca_par_processus_respecte_le_perimetre_d_agence(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('manager');
        $manager->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $parCode = collect($this->props($manager)['ca_par_processus'])->keyBy('code');

        $this->assertEquals(100_000, $parCode['vente']['montant']);
        $this->assertEquals(100_000, $parCode->sum('montant'));
    }
}
