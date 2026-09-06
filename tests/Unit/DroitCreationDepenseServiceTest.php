<?php

namespace Tests\Unit;

use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\DroitCreationDepenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DroitCreationDepenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private DroitCreationDepenseService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DroitCreationDepenseService;
        $this->org = Organization::factory()->create();
    }

    /**
     * Admin Entreprise : bypass RBAC/agences (isAdmin()) mais PAS le plafond
     * de montant depuis le 04/09/2026 — cf. superAdminUser() pour le seul
     * rôle réellement sans plafond (docs/depenses-validation.md, DEPVAL-001).
     */
    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function superAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function commercialeUser(): User
    {
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('commerciale');

        return $user;
    }

    private function site(): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    // ── peutCreer ─────────────────────────────────────────────────────────────

    public function test_admin_peut_toujours_creer(): void
    {
        $this->assertTrue($this->service->peutCreer($this->adminUser(), $this->org->id));
    }

    public function test_commerciale_avec_droit_actif_peut_creer(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => true,
        ]);

        $this->assertTrue($this->service->peutCreer($user, $this->org->id));
    }

    public function test_commerciale_sans_droit_ne_peut_pas_creer(): void
    {
        $this->assertFalse(
            $this->service->peutCreer($this->commercialeUser(), $this->org->id)
        );
    }

    public function test_commerciale_avec_droit_inactif_ne_peut_pas_creer(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => false,
        ]);

        $this->assertFalse($this->service->peutCreer($user, $this->org->id));
    }

    // ── peutCreerSurSite ──────────────────────────────────────────────────────

    public function test_admin_peut_creer_sur_nimporte_quel_site(): void
    {
        $site = $this->site();
        $this->assertTrue(
            $this->service->peutCreerSurSite($this->adminUser(), $this->org->id, $site->id)
        );
    }

    public function test_toutes_agences_peut_creer_sur_nimporte_quel_site(): void
    {
        $user = $this->commercialeUser();
        $site = $this->site();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => true,
        ]);

        $this->assertTrue($this->service->peutCreerSurSite($user, $this->org->id, $site->id));
    }

    public function test_agences_selectionnees_peut_creer_sur_site_autorise(): void
    {
        $user = $this->commercialeUser();
        $siteA = $this->site();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$siteA->id],
            'is_actif' => true,
        ]);

        $this->assertTrue($this->service->peutCreerSurSite($user, $this->org->id, $siteA->id));
    }

    public function test_agences_selectionnees_ne_peut_pas_creer_sur_site_non_autorise(): void
    {
        $user = $this->commercialeUser();
        $siteA = $this->site();
        $siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$siteA->id],
            'is_actif' => true,
        ]);

        $this->assertFalse($this->service->peutCreerSurSite($user, $this->org->id, $siteB->id));
    }

    public function test_sans_droit_ne_peut_creer_sur_aucun_site(): void
    {
        $site = $this->site();
        $this->assertFalse(
            $this->service->peutCreerSurSite($this->commercialeUser(), $this->org->id, $site->id)
        );
    }

    // ── sitesAutorises ────────────────────────────────────────────────────────

    public function test_admin_sites_autorises_retourne_null(): void
    {
        $this->assertNull($this->service->sitesAutorises($this->adminUser(), $this->org->id));
    }

    public function test_toutes_agences_sites_autorises_retourne_null(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'is_actif' => true,
        ]);

        $this->assertNull($this->service->sitesAutorises($user, $this->org->id));
    }

    public function test_agences_selectionnees_sites_autorises_retourne_sites(): void
    {
        $user = $this->commercialeUser();
        $site = $this->site();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$site->id],
            'is_actif' => true,
        ]);

        $result = $this->service->sitesAutorises($user, $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertEquals($site->id, $result->first()->id);
    }

    public function test_sans_droit_sites_autorises_retourne_collection_vide(): void
    {
        $result = $this->service->sitesAutorises($this->commercialeUser(), $this->org->id);
        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    // ── droitValidationPour ───────────────────────────────────────────────────

    public function test_super_admin_droit_validation_pour_retourne_null(): void
    {
        $this->assertNull($this->service->droitValidationPour($this->superAdminUser(), $this->org->id));
    }

    public function test_admin_entreprise_sans_droit_configure_droit_validation_pour_retourne_null(): void
    {
        // Contrairement à super_admin, ce null ne signifie pas "bypass" mais
        // "aucun plafond configuré" — traité comme deny-by-default ailleurs
        // (cf. peutValiderMontant).
        $this->assertNull($this->service->droitValidationPour($this->adminUser(), $this->org->id));
    }

    public function test_admin_entreprise_avec_droit_configure_droit_validation_pour_retourne_le_droit(): void
    {
        $user = $this->adminUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $result = $this->service->droitValidationPour($user, $this->org->id);
        $this->assertNotNull($result);
        $this->assertEquals($droit->id, $result->id);
    }

    public function test_non_admin_sans_droit_validation_retourne_null(): void
    {
        $this->assertNull($this->service->droitValidationPour($this->commercialeUser(), $this->org->id));
    }

    public function test_non_admin_avec_droit_validation_retourne_droit(): void
    {
        $user = $this->commercialeUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
        ]);

        $result = $this->service->droitValidationPour($user, $this->org->id);
        $this->assertNotNull($result);
        $this->assertEquals($droit->id, $result->id);
    }

    public function test_non_admin_avec_droit_peut_valider_false_retourne_null(): void
    {
        $user = $this->commercialeUser();
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => false,
        ]);

        $this->assertNull($this->service->droitValidationPour($user, $this->org->id));
    }

    // ── peutValiderSurSite ────────────────────────────────────────────────────

    public function test_admin_peut_valider_sur_site_retourne_true(): void
    {
        $site = $this->site();
        $this->assertTrue($this->service->peutValiderSurSite($this->adminUser(), null, $site->id));
    }

    public function test_sans_droit_peut_valider_sur_site_retourne_false(): void
    {
        $site = $this->site();
        $this->assertFalse($this->service->peutValiderSurSite($this->commercialeUser(), null, $site->id));
    }

    public function test_toutes_agences_peut_valider_sur_site_retourne_true(): void
    {
        $user = $this->commercialeUser();
        $site = $this->site();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
        ]);

        $this->assertTrue($this->service->peutValiderSurSite($user, $droit, $site->id));
    }

    public function test_son_agence_peut_valider_sur_site_meme_site_retourne_true(): void
    {
        $user = $this->commercialeUser();
        $site = $this->site();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'son_agence',
            'sites' => null,
            'peut_valider' => true,
        ]);

        $this->assertTrue($this->service->peutValiderSurSite($user, $droit, $site->id));
    }

    public function test_son_agence_peut_valider_sur_site_autre_site_retourne_false(): void
    {
        $user = $this->commercialeUser();
        $siteA = $this->site();
        $siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $user->sites()->attach($siteA->id, ['role' => 'employe', 'is_default' => true]);

        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'son_agence',
            'sites' => null,
            'peut_valider' => true,
        ]);

        $this->assertFalse($this->service->peutValiderSurSite($user, $droit, $siteB->id));
    }

    public function test_agences_selectionnees_peut_valider_sur_site_site_autorise_retourne_true(): void
    {
        $user = $this->commercialeUser();
        $site = $this->site();

        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$site->id],
            'peut_valider' => true,
        ]);

        $this->assertTrue($this->service->peutValiderSurSite($user, $droit, $site->id));
    }

    public function test_agences_selectionnees_peut_valider_sur_site_site_non_autorise_retourne_false(): void
    {
        $user = $this->commercialeUser();
        $siteA = $this->site();
        $siteB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$siteA->id],
            'peut_valider' => true,
        ]);

        $this->assertFalse($this->service->peutValiderSurSite($user, $droit, $siteB->id));
    }

    // ── peutValiderMontant ────────────────────────────────────────────────────

    public function test_super_admin_peut_valider_montant_retourne_toujours_true(): void
    {
        $this->assertTrue(
            $this->service->peutValiderMontant($this->superAdminUser(), null, 50_000_000)
        );
    }

    public function test_admin_entreprise_ne_bypasse_plus_le_plafond(): void
    {
        $user = $this->adminUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->assertTrue($this->service->peutValiderMontant($user, $droit, 500000));
        $this->assertFalse($this->service->peutValiderMontant($user, $droit, 500001));
    }

    public function test_admin_entreprise_sans_droit_ne_peut_rien_valider(): void
    {
        $this->assertFalse(
            $this->service->peutValiderMontant($this->adminUser(), null, 1)
        );
    }

    public function test_sans_droit_peut_valider_montant_retourne_false(): void
    {
        $this->assertFalse(
            $this->service->peutValiderMontant($this->commercialeUser(), null, 1)
        );
    }

    public function test_montant_egal_au_plafond_retourne_true(): void
    {
        $user = $this->commercialeUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->assertTrue($this->service->peutValiderMontant($user, $droit, 500000));
    }

    public function test_montant_sous_le_plafond_retourne_true(): void
    {
        $user = $this->commercialeUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->assertTrue($this->service->peutValiderMontant($user, $droit, 499999));
    }

    public function test_montant_au_dessus_du_plafond_retourne_false(): void
    {
        $user = $this->commercialeUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->assertFalse($this->service->peutValiderMontant($user, $droit, 500001));
    }

    public function test_plafond_non_configure_est_traite_comme_zero(): void
    {
        $user = $this->commercialeUser();
        $droit = DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'commerciale',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => null,
        ]);

        $this->assertFalse($this->service->peutValiderMontant($user, $droit, 1));
    }
}
