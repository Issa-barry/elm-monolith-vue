<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransfertLogistiqueIndexFilterTest extends TestCase
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

    private function makeSite(Organization $org, string $nom): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
    }

    private function makeAdminUser(Organization $org, Site $site): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeNonAdminUser(Organization $org, Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'logistique.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['logistique.create', 'logistique.read']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function creerTransfert(Organization $org, Site $source, Site $dest, Vehicule $vehicule, User $user, array $attributs = []): TransfertLogistique
    {
        $this->actingAs($user);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'site_source_id' => $source->id,
            'site_destination_id' => $dest->id,
            'vehicule_id' => $vehicule->id,
            ...$attributs,
        ]);
    }

    private function makeEquipe(Organization $org, Vehicule $vehicule, string $nomLivreur, string $telephone): EquipeLivraison
    {
        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'nom_complet' => $nomLivreur,
            'telephone' => $telephone,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        return $equipe;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_admin_filtre_par_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin);

        $response = $this->actingAs($admin)
            ->get('/backoffice/logistique/transferts?depart_site_ids[]='.$siteA->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }

    public function test_admin_filtre_par_site_arrivee(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $this->creerTransfert($org, $siteA, $siteC, $vehicule, $admin);

        $response = $this->actingAs($admin)
            ->get('/backoffice/logistique/transferts?arrivee_site_ids[]='.$siteB->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_destination_nom', 'Site B')
        );
    }

    public function test_admin_filtre_depart_et_arrivee_combines(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // doit rester
        $this->creerTransfert($org, $siteA, $siteC, $vehicule, $admin); // éliminé par arrivée
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // éliminé par départ

        $response = $this->actingAs($admin)
            ->get('/backoffice/logistique/transferts?depart_site_ids[]='.$siteA->id.'&arrivee_site_ids[]='.$siteB->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
            ->where('transferts.0.site_destination_nom', 'Site B')
        );
    }

    public function test_non_admin_voit_seulement_transferts_de_son_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);
        $nonAdmin = $this->makeNonAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // visible
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // invisible

        $response = $this->actingAs($nonAdmin)->get('/backoffice/logistique/transferts');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }

    public function test_non_admin_ne_peut_pas_forcer_un_autre_site_depart(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);
        $nonAdmin = $this->makeNonAdminUser($org, $siteA);

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin); // son site
        $this->creerTransfert($org, $siteC, $siteB, $vehicule, $admin); // site interdit

        // Tente de forcer siteC via query params
        $response = $this->actingAs($nonAdmin)
            ->get('/backoffice/logistique/transferts?depart_site_ids[]='.$siteC->id);

        $response->assertOk();
        // Doit voir seulement son propre site A, le param siteC est ignoré
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Logistique/Index')
            ->has('transferts', 1)
            ->where('transferts.0.site_source_nom', 'Site A')
        );
    }

    public function test_filtre_par_periode_sur_la_date_du_transfert(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehicule = $this->makeVehicule($org);
        $admin = $this->makeAdminUser($org, $siteA);

        $ancien = $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $ancien->forceFill(['created_at' => '2026-09-10 10:00:00'])->saveQuietly();
        $recent = $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin);
        $recent->forceFill(['created_at' => '2026-10-17 23:30:00'])->saveQuietly();

        $this->actingAs($admin)
            ->get('/backoffice/logistique/transferts?date_debut=2026-10-01&date_fin=2026-10-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transferts', 1)
                ->where('transferts.0.id', $recent->id)
                ->where('filtres.date_debut', '2026-10-01')
                ->where('filtres.date_fin', '2026-10-17')
            );
    }

    public function test_filtre_par_vehicule_nom_ou_immatriculation(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $balla = $this->makeVehicule($org);
        $balla->update(['nom_vehicule' => 'Balla', 'immatriculation' => 'RC-888896']);
        $autre = $this->makeVehicule($org);
        $autre->update(['nom_vehicule' => 'Makoko', 'immatriculation' => 'RC-111111']);
        $admin = $this->makeAdminUser($org, $siteA);

        $attendu = $this->creerTransfert($org, $siteA, $siteB, $balla, $admin);
        $this->creerTransfert($org, $siteA, $siteB, $autre, $admin);

        foreach (['balla', '888896'] as $saisie) {
            $this->actingAs($admin)
                ->get('/backoffice/logistique/transferts?vehicule='.$saisie)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('transferts', 1)
                    ->where('transferts.0.id', $attendu->id)
                );
        }
    }

    public function test_receptions_filtre_par_livreur_de_l_equipe_du_transfert(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehiculeA = $this->makeVehicule($org);
        $vehiculeB = $this->makeVehicule($org);
        $equipeA = $this->makeEquipe($org, $vehiculeA, 'Mamadou Diallo', '+224620000001');
        $equipeB = $this->makeEquipe($org, $vehiculeB, 'Alpha Camara', '+224620000002');
        $admin = $this->makeAdminUser($org, $siteA);
        $transit = ['statut' => StatutTransfert::TRANSIT->value];

        $attendu = $this->creerTransfert($org, $siteA, $siteB, $vehiculeA, $admin, [...$transit, 'equipe_livraison_id' => $equipeA->id]);
        $this->creerTransfert($org, $siteA, $siteB, $vehiculeB, $admin, [...$transit, 'equipe_livraison_id' => $equipeB->id]);

        foreach (['diallo', '620000001'] as $saisie) {
            $this->actingAs($admin)
                ->get('/backoffice/logistique/receptions?livreur='.$saisie)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('vue', 'receptions')
                    ->has('transferts', 1)
                    ->where('transferts.0.id', $attendu->id)
                    ->where('filtres.livreur', $saisie)
                );
        }
    }

    public function test_filtre_livreur_retombe_sur_l_equipe_du_vehicule_sans_equipe_sur_le_transfert(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $vehiculeA = $this->makeVehicule($org);
        $vehiculeB = $this->makeVehicule($org);
        $this->makeEquipe($org, $vehiculeA, 'Mamadou Diallo', '+224620000001');
        $this->makeEquipe($org, $vehiculeB, 'Alpha Camara', '+224620000002');
        $admin = $this->makeAdminUser($org, $siteA);

        $attendu = $this->creerTransfert($org, $siteA, $siteB, $vehiculeA, $admin);
        $this->creerTransfert($org, $siteA, $siteB, $vehiculeB, $admin);

        $this->actingAs($admin)
            ->get('/backoffice/logistique/transferts?livreur=Mamadou')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transferts', 1)
                ->where('transferts.0.id', $attendu->id)
            );
    }

    public function test_filtres_ne_sortent_pas_du_perimetre_d_agence(): void
    {
        $org = $this->makeOrg();
        $siteA = $this->makeSite($org, 'Site A');
        $siteB = $this->makeSite($org, 'Site B');
        $siteC = $this->makeSite($org, 'Site C');
        $vehicule = $this->makeVehicule($org);
        $vehicule->update(['nom_vehicule' => 'Balla']);
        $admin = $this->makeAdminUser($org, $siteA);
        $nonAdmin = $this->makeNonAdminUser($org, $siteB);
        $transit = ['statut' => StatutTransfert::TRANSIT->value];

        $this->creerTransfert($org, $siteA, $siteB, $vehicule, $admin, $transit); // reçu par son agence
        $this->creerTransfert($org, $siteA, $siteC, $vehicule, $admin, $transit); // autre agence

        $this->actingAs($nonAdmin)
            ->get('/backoffice/logistique/receptions?vehicule=Balla')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transferts', 1)
                ->where('transferts.0.site_destination_nom', 'Site B')
            );
    }
}
