<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use App\Policies\LivreurPolicy;
use App\Policies\PrestatairePolicy;
use App\Policies\ProprietairePolicy;
use App\Policies\VehiculePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(Organization $org, array $permissions): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function userWithoutPermissions(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    // ── LivreurPolicy ─────────────────────────────────────────────────────────

    public function test_livreur_policy_view_any_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['livreurs.read']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new LivreurPolicy;

        $this->assertTrue($policy->viewAny($userWith));
        $this->assertFalse($policy->viewAny($userWithout));
    }

    public function test_livreur_policy_view_checks_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['livreurs.read']);
        $livreurSameOrg = Livreur::factory()->create(['organization_id' => $org->id]);
        $livreurOtherOrg = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new LivreurPolicy;

        $this->assertTrue($policy->view($userWith, $livreurSameOrg));
        $this->assertFalse($policy->view($userWith, $livreurOtherOrg));
    }

    public function test_livreur_policy_create_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['livreurs.create']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new LivreurPolicy;

        $this->assertTrue($policy->create($userWith));
        $this->assertFalse($policy->create($userWithout));
    }

    public function test_livreur_policy_update_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['livreurs.update']);
        $livreurSameOrg = Livreur::factory()->create(['organization_id' => $org->id]);
        $livreurOtherOrg = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new LivreurPolicy;

        $this->assertTrue($policy->update($userWith, $livreurSameOrg));
        $this->assertFalse($policy->update($userWith, $livreurOtherOrg));
    }

    public function test_livreur_policy_delete_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['livreurs.delete']);
        $livreurSameOrg = Livreur::factory()->create(['organization_id' => $org->id]);
        $livreurOtherOrg = Livreur::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new LivreurPolicy;

        $this->assertTrue($policy->delete($userWith, $livreurSameOrg));
        $this->assertFalse($policy->delete($userWith, $livreurOtherOrg));
    }

    public function test_livreur_policy_delete_returns_false_without_permission(): void
    {
        $org = Organization::factory()->create();
        $userWithout = $this->userWithoutPermissions($org);
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);

        $policy = new LivreurPolicy;

        $this->assertFalse($policy->delete($userWithout, $livreur));
    }

    // ── PrestatairePolicy ──────────────────────────────────────────────────────

    public function test_prestataire_policy_view_any_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['prestataires.read']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new PrestatairePolicy;

        $this->assertTrue($policy->viewAny($userWith));
        $this->assertFalse($policy->viewAny($userWithout));
    }

    public function test_prestataire_policy_view_checks_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['prestataires.read']);
        $prestataireSameOrg = Prestataire::create(['organization_id' => $org->id, 'nom' => 'TEST', 'type' => 'fournisseur']);
        $prestataireOtherOrg = Prestataire::create(['organization_id' => $otherOrg->id, 'nom' => 'OTHER', 'type' => 'fournisseur']);

        $policy = new PrestatairePolicy;

        $this->assertTrue($policy->view($userWith, $prestataireSameOrg));
        $this->assertFalse($policy->view($userWith, $prestataireOtherOrg));
    }

    public function test_prestataire_policy_create_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['prestataires.create']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new PrestatairePolicy;

        $this->assertTrue($policy->create($userWith));
        $this->assertFalse($policy->create($userWithout));
    }

    public function test_prestataire_policy_update_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['prestataires.update']);
        $prestataireSameOrg = Prestataire::create(['organization_id' => $org->id, 'nom' => 'TEST', 'type' => 'fournisseur']);
        $prestataireOtherOrg = Prestataire::create(['organization_id' => $otherOrg->id, 'nom' => 'OTHER', 'type' => 'fournisseur']);

        $policy = new PrestatairePolicy;

        $this->assertTrue($policy->update($userWith, $prestataireSameOrg));
        $this->assertFalse($policy->update($userWith, $prestataireOtherOrg));
    }

    public function test_prestataire_policy_delete_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['prestataires.delete']);
        $prestataireSameOrg = Prestataire::create(['organization_id' => $org->id, 'nom' => 'TEST', 'type' => 'fournisseur']);
        $prestataireOtherOrg = Prestataire::create(['organization_id' => $otherOrg->id, 'nom' => 'OTHER', 'type' => 'fournisseur']);

        $policy = new PrestatairePolicy;

        $this->assertTrue($policy->delete($userWith, $prestataireSameOrg));
        $this->assertFalse($policy->delete($userWith, $prestataireOtherOrg));
    }

    public function test_prestataire_policy_delete_returns_false_without_permission(): void
    {
        $org = Organization::factory()->create();
        $userWithout = $this->userWithoutPermissions($org);
        $prestataire = Prestataire::create(['organization_id' => $org->id, 'nom' => 'TEST', 'type' => 'fournisseur']);

        $policy = new PrestatairePolicy;

        $this->assertFalse($policy->delete($userWithout, $prestataire));
    }

    // ── ProprietairePolicy ────────────────────────────────────────────────────

    public function test_proprietaire_policy_view_any_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['proprietaires.read']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new ProprietairePolicy;

        $this->assertTrue($policy->viewAny($userWith));
        $this->assertFalse($policy->viewAny($userWithout));
    }

    public function test_proprietaire_policy_view_checks_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['proprietaires.read']);
        $proprietaireSameOrg = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $proprietaireOtherOrg = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new ProprietairePolicy;

        $this->assertTrue($policy->view($userWith, $proprietaireSameOrg));
        $this->assertFalse($policy->view($userWith, $proprietaireOtherOrg));
    }

    public function test_proprietaire_policy_create_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['proprietaires.create']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new ProprietairePolicy;

        $this->assertTrue($policy->create($userWith));
        $this->assertFalse($policy->create($userWithout));
    }

    public function test_proprietaire_policy_update_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['proprietaires.update']);
        $proprietaireSameOrg = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $proprietaireOtherOrg = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new ProprietairePolicy;

        $this->assertTrue($policy->update($userWith, $proprietaireSameOrg));
        $this->assertFalse($policy->update($userWith, $proprietaireOtherOrg));
    }

    public function test_proprietaire_policy_delete_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['proprietaires.delete']);
        $proprietaireSameOrg = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $proprietaireOtherOrg = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $policy = new ProprietairePolicy;

        $this->assertTrue($policy->delete($userWith, $proprietaireSameOrg));
        $this->assertFalse($policy->delete($userWith, $proprietaireOtherOrg));
    }

    public function test_proprietaire_policy_delete_returns_false_without_permission(): void
    {
        $org = Organization::factory()->create();
        $userWithout = $this->userWithoutPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $policy = new ProprietairePolicy;

        $this->assertFalse($policy->delete($userWithout, $proprietaire));
    }

    // ── VehiculePolicy ────────────────────────────────────────────────────────

    public function test_vehicule_policy_view_any_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['vehicules.read']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new VehiculePolicy;

        $this->assertTrue($policy->viewAny($userWith));
        $this->assertFalse($policy->viewAny($userWithout));
    }

    public function test_vehicule_policy_view_checks_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['vehicules.read']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $otherProprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);
        $vehiculeSameOrg = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);
        $vehiculeOtherOrg = Vehicule::factory()->create(['organization_id' => $otherOrg->id, 'proprietaire_id' => $otherProprietaire->id]);

        $policy = new VehiculePolicy;

        $this->assertTrue($policy->view($userWith, $vehiculeSameOrg));
        $this->assertFalse($policy->view($userWith, $vehiculeOtherOrg));
    }

    public function test_vehicule_policy_create_requires_permission(): void
    {
        $org = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['vehicules.create']);
        $userWithout = $this->userWithoutPermissions($org);

        $policy = new VehiculePolicy;

        $this->assertTrue($policy->create($userWith));
        $this->assertFalse($policy->create($userWithout));
    }

    public function test_vehicule_policy_update_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['vehicules.update']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $otherProprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);
        $vehiculeSameOrg = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);
        $vehiculeOtherOrg = Vehicule::factory()->create(['organization_id' => $otherOrg->id, 'proprietaire_id' => $otherProprietaire->id]);

        $policy = new VehiculePolicy;

        $this->assertTrue($policy->update($userWith, $vehiculeSameOrg));
        $this->assertFalse($policy->update($userWith, $vehiculeOtherOrg));
    }

    public function test_vehicule_policy_delete_checks_permission_and_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $userWith = $this->userWithPermissions($org, ['vehicules.delete']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $otherProprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);
        $vehiculeSameOrg = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);
        $vehiculeOtherOrg = Vehicule::factory()->create(['organization_id' => $otherOrg->id, 'proprietaire_id' => $otherProprietaire->id]);

        $policy = new VehiculePolicy;

        $this->assertTrue($policy->delete($userWith, $vehiculeSameOrg));
        $this->assertFalse($policy->delete($userWith, $vehiculeOtherOrg));
    }

    public function test_vehicule_policy_delete_returns_false_without_permission(): void
    {
        $org = Organization::factory()->create();
        $userWithout = $this->userWithoutPermissions($org);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id, 'proprietaire_id' => $proprietaire->id]);

        $policy = new VehiculePolicy;

        $this->assertFalse($policy->delete($userWithout, $vehicule));
    }
}
