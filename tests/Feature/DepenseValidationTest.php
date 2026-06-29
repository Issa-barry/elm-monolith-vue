<?php

namespace Tests\Feature;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests du workflow de validation des dépenses :
 * - DepensePolicy::valider (bypass admin, RBAC, DroitCreationDepense périmètre)
 * - can_valider par ligne dans l'index
 * - Rejet du validateur d'une autre org
 */
class DepenseValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    private DepenseType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->siteA = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence A',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $this->type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
        ]);

        foreach (['admin_entreprise', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['depenses.read', 'depenses.create', 'depenses.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function adminUser(Site $site = null): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['depenses.read', 'depenses.create', 'depenses.update']);
        $user->sites()->attach(($site ?? $this->siteA)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function managerUser(Site $site = null): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['depenses.read', 'depenses.create', 'depenses.update']);
        $user->sites()->attach(($site ?? $this->siteA)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function depenseSoumise(Site $site = null): Depense
    {
        return Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $this->type->id,
            'site_id' => ($site ?? $this->siteA)->id,
        ]);
    }

    private function droitValider(string $perimetre, array $sites = []): DroitCreationDepense
    {
        return DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => $perimetre,
            'sites' => $perimetre === 'agences_selectionnees' ? $sites : null,
            'peut_valider' => true,
        ]);
    }

    // ── Admin bypass ──────────────────────────────────────────────────────────

    public function test_admin_peut_valider_depense_sur_son_site(): void
    {
        $admin = $this->adminUser($this->siteA);
        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($admin)
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    public function test_admin_peut_valider_depense_autre_agence(): void
    {
        // Admin attaché à siteA, dépense sur siteB → doit quand même valider
        $admin = $this->adminUser($this->siteA);
        $depense = $this->depenseSoumise($this->siteB);

        $this->actingAs($admin)
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    // ── Manager — toutes_agences ──────────────────────────────────────────────

    public function test_manager_toutes_agences_peut_valider_nimporte_quel_site(): void
    {
        $manager = $this->managerUser($this->siteA);
        $this->droitValider('toutes_agences');
        $depense = $this->depenseSoumise($this->siteB);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    // ── Manager — son_agence ──────────────────────────────────────────────────

    public function test_manager_son_agence_peut_valider_son_site(): void
    {
        $manager = $this->managerUser($this->siteA);
        $this->droitValider('son_agence');
        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    public function test_manager_son_agence_ne_peut_pas_valider_autre_site(): void
    {
        $manager = $this->managerUser($this->siteA); // attaché à siteA
        $this->droitValider('son_agence');
        $depense = $this->depenseSoumise($this->siteB); // dépense sur siteB

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertForbidden();
    }

    // ── Manager — agences_selectionnees ───────────────────────────────────────

    public function test_manager_agences_selectionnees_peut_valider_site_autorise(): void
    {
        $manager = $this->managerUser($this->siteA);
        $this->droitValider('agences_selectionnees', [$this->siteA->id]);
        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    public function test_manager_agences_selectionnees_ne_peut_pas_valider_site_non_autorise(): void
    {
        $manager = $this->managerUser($this->siteA);
        $this->droitValider('agences_selectionnees', [$this->siteA->id]); // siteA autorisé, pas siteB
        $depense = $this->depenseSoumise($this->siteB);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertForbidden();
    }

    // ── Manager — sans droit ──────────────────────────────────────────────────

    public function test_manager_sans_droit_valider_recoit_403(): void
    {
        $manager = $this->managerUser($this->siteA);
        // Aucune ligne DroitCreationDepense → ne peut pas valider
        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertForbidden();
    }

    public function test_manager_avec_peut_valider_false_recoit_403(): void
    {
        $manager = $this->managerUser($this->siteA);
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => false,
        ]);
        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($manager)
            ->patch(route('depenses.valider', $depense))
            ->assertForbidden();
    }

    // ── Org isolation ─────────────────────────────────────────────────────────

    public function test_admin_autre_org_ne_peut_pas_valider(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Site Autre Org',
            'type' => 'depot',
            'localisation' => 'Dakar',
        ]);
        $autreAdmin = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreAdmin->assignRole('admin_entreprise');
        $autreAdmin->givePermissionTo(['depenses.read', 'depenses.update']);
        $autreAdmin->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $depense = $this->depenseSoumise($this->siteA);

        $this->actingAs($autreAdmin)
            ->patch(route('depenses.valider', $depense))
            ->assertForbidden();
    }

    // ── Statut non-soumis ─────────────────────────────────────────────────────

    public function test_ne_peut_pas_valider_depense_brouillon(): void
    {
        $admin = $this->adminUser();
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->siteA->id,
        ]);

        // La policy bloque (statut pas SOUMIS n'est pas dans les conditions can_valider)
        // mais le contrôleur retourne une erreur (pas 403), donc assertRedirectContains ou assertSessionHasErrors
        $this->actingAs($admin)
            ->patch(route('depenses.valider', $depense))
            ->assertSessionHasErrors(['statut']);

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::BROUILLON->value,
        ]);
    }

    // ── can_valider dans l'index ──────────────────────────────────────────────

    public function test_index_can_valider_true_pour_admin_sur_depense_soumise(): void
    {
        $admin = $this->adminUser($this->siteA);
        $this->depenseSoumise($this->siteB); // site différent

        $this->actingAs($admin)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Depenses/Index')
                ->has('depenses.data', 1)
                ->where('depenses.data.0.can_valider', true)
            );
    }

    public function test_index_can_valider_false_pour_manager_sans_droit(): void
    {
        $manager = $this->managerUser($this->siteA);
        $this->depenseSoumise($this->siteA);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.data.0.can_valider', false)
            );
    }

    public function test_index_can_valider_false_pour_depense_non_soumise(): void
    {
        $admin = $this->adminUser();
        Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->siteA->id,
        ]);

        $this->actingAs($admin)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.data.0.can_valider', false)
            );
    }
}
