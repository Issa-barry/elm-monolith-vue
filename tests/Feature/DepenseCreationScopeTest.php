<?php

namespace Tests\Feature;

use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepenseCreationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private DepenseType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Principale',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Fournitures bureau',
        ]);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'depenses.create', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('depenses.create');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function commercialeUser(): User
    {
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'depenses.create', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('commerciale');
        $user->givePermissionTo('depenses.create');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function storePayload(): array
    {
        return [
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
            'montant' => 5000,
            'date_depense' => now()->toDateString(),
            'statut' => 'brouillon',
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_admin_peut_creer_depense_sans_droit_configure(): void
    {
        $this->actingAs($this->adminUser())
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertRedirect();
    }

    public function test_commerciale_sans_droit_recoit_403(): void
    {
        $this->actingAs($this->commercialeUser())
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertForbidden();
    }

    public function test_commerciale_avec_droit_toutes_agences_peut_creer(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => true,
        ]);

        $this->actingAs($user)
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertRedirect();
    }

    public function test_commerciale_avec_droit_agences_selectionnees_peut_creer_sur_site_autorise(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$this->site->id],
            'is_actif' => true,
        ]);

        $this->actingAs($user)
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertRedirect();
    }

    public function test_commerciale_avec_droit_agences_selectionnees_recoit_403_sur_site_non_autorise(): void
    {
        $autresite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Labe',
        ]);

        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$autresite->id],
            'is_actif' => true,
        ]);

        $this->actingAs($user)
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertForbidden();
    }

    public function test_commerciale_avec_droit_inactif_recoit_403(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => false,
        ]);

        $this->actingAs($user)
            ->post('/backoffice/depenses', $this->storePayload())
            ->assertForbidden();
    }
}
