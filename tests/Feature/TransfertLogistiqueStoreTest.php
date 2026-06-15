<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransfertLogistiqueStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::LOGISTIQUE);

        return $org;
    }

    private function makeUser(Organization $org, Site $defaultSite): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read',   'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeSite(Organization $org, string $nom = 'Site A'): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Organization $org, string $categorie = 'interne'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'categorie' => $categorie,
            'is_active' => true,
        ]);
    }

    private function makeProduit(Organization $org): Produit
    {
        return Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit Test',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_store_cree_transfert_et_redirige_vers_edit(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehicule = $this->makeVehicule($org);
        $produit = $this->makeProduit($org);
        $user = $this->makeUser($org, $siteA);

        $response = $this->actingAs($user)->post('/logistique', [
            'site_source_id' => $siteA->id,
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'notes' => 'Test store',
            'lignes' => [
                ['produit_id' => $produit->id, 'quantite_demandee' => 10, 'notes' => ''],
            ],
        ]);

        $transfert = TransfertLogistique::where('organization_id', $org->id)->first();
        $this->assertNotNull($transfert, 'Le transfert doit être créé en base.');

        $response->assertRedirectToRoute('logistique.show', $transfert);

        $this->assertDatabaseHas('transferts_logistiques', [
            'organization_id' => $org->id,
            'site_source_id' => $siteA->id,
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
        ]);

        $this->assertDatabaseHas('transfert_lignes', [
            'transfert_logistique_id' => $transfert->id,
            'produit_id' => $produit->id,
            'quantite_demandee' => 10,
        ]);
    }

    public function test_admin_peut_choisir_site_source_librement(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $produit = $this->makeProduit($org);
        // Admin affecté à siteA mais crée le transfert depuis siteC
        $user = $this->makeUser($org, $siteA);

        $this->actingAs($user)->post('/logistique', [
            'site_source_id' => $siteC->id,
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'quantite_demandee' => 5, 'notes' => ''],
            ],
        ]);

        $this->assertDatabaseHas('transferts_logistiques', [
            'organization_id' => $org->id,
            'site_source_id' => $siteC->id,
            'site_destination_id' => $siteB->id,
        ]);
    }

    public function test_non_admin_site_source_ignore_depuis_requete(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $produit = $this->makeProduit($org);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read',   'guard_name' => 'web']);
        // Utilisateur avec rôle non-admin (manager), affecté à siteA
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($siteA->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->post('/logistique', [
            'site_source_id' => $siteC->id, // tentative de forcer un autre site
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'quantite_demandee' => 5, 'notes' => ''],
            ],
        ]);

        // site_source_id doit être siteA (site par défaut), pas siteC
        $this->assertDatabaseHas('transferts_logistiques', [
            'organization_id' => $org->id,
            'site_source_id' => $siteA->id,
        ]);
        $this->assertDatabaseMissing('transferts_logistiques', [
            'site_source_id' => $siteC->id,
        ]);
    }

    public function test_store_refuse_vehicule_externe(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehicule = $this->makeVehicule($org, 'externe');
        $produit = $this->makeProduit($org);
        $user = $this->makeUser($org, $siteA);

        $response = $this->actingAs($user)->post('/logistique', [
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'quantite_demandee' => 5, 'notes' => ''],
            ],
        ]);

        $response->assertSessionHasErrors('vehicule_id');
        $this->assertDatabaseCount('transferts_logistiques', 0);
    }

    public function test_store_refuse_sans_lignes(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehicule = $this->makeVehicule($org);
        $user = $this->makeUser($org, $siteA);

        $response = $this->actingAs($user)->post('/logistique', [
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [],
        ]);

        $response->assertSessionHasErrors('lignes');
        $this->assertDatabaseCount('transferts_logistiques', 0);
    }

    public function test_store_redirige_si_non_authentifie(): void
    {
        $this->post('/logistique', [])->assertRedirect('/login');
    }

    public function test_store_refuse_si_permission_manquante(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        // pas de logistique.create
        $user->sites()->attach($siteA->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->post('/logistique', [
            'site_destination_id' => 999,
            'vehicule_id' => 999,
            'lignes' => [],
        ])->assertForbidden();
    }
}
