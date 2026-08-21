<?php

namespace Tests\Feature;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Rh\EmployeAffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Intégration HTTP de EmployeAffectationService : le site/la fonction d'un employé passent
 * TOUJOURS par ce service (jamais une écriture directe de employes.site_id), que ce soit via le
 * formulaire d'édition classique ou l'action dédiée "transférer de site" — cf. plan §2.
 */
class EmployeAffectationTest extends TestCase
{
    use RefreshDatabase;

    /** Le cache de permissions Spatie persiste pour tout le processus PHPUnit — cf. RoleController. */
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        // EmployePolicy::update() exige la permission rh-employes.update, le rôle seul ne
        // suffit pas (aucun bypass hors super_admin, cf. AuthServiceProvider::Gate::before).
        Permission::firstOrCreate(['name' => 'rh-employes.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('rh-employes.update');
        $this->attachSite($org, $user, 'Site Admin');

        return $user;
    }

    private function attachSite(Organization $org, User $user, string $nom = 'Site Admin'): Site
    {
        $site = Site::create(['organization_id' => $org->id, 'nom' => $nom, 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $site;
    }

    private function employe(Organization $org): Employe
    {
        return Employe::create([
            'organization_id' => $org->id,
            'personne_id' => Personne::factory()->create(['organization_id' => $org->id])->id,
            'matricule' => 'EMP-100',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);
    }

    public function test_update_avec_un_site_ouvre_une_affectation_synchronisee_avec_le_cache(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $employe = $this->employe($org);
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt A', 'type' => 'depot']);

        $this->actingAs($admin)->put("/backoffice/employes/{$employe->id}", [
            'nom' => 'DIALLO', 'prenom' => 'Mamadou', 'email' => null, 'telephone' => null,
            'type_employe' => 'interne', 'site_id' => $site->id, 'statut' => 'actif',
        ])->assertRedirect();

        $employe->refresh();
        $this->assertSame($site->id, $employe->site_id);
        $this->assertSame(1, $employe->affectations()->count());
        $this->assertSame('affectation_initiale', $employe->affectationActive->motif);
    }

    public function test_update_avec_un_nouveau_site_transfere_sans_perdre_lhistorique(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $employe = $this->employe($org);
        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt A', 'type' => 'depot']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt B', 'type' => 'depot']);

        $payload = fn (string $siteId) => [
            'nom' => 'DIALLO', 'prenom' => 'Mamadou', 'email' => null, 'telephone' => null,
            'type_employe' => 'interne', 'site_id' => $siteId, 'statut' => 'actif',
        ];

        $this->actingAs($admin)->put("/backoffice/employes/{$employe->id}", $payload($siteA->id));
        $this->actingAs($admin)->put("/backoffice/employes/{$employe->id}", $payload($siteB->id));

        $employe->refresh();
        $this->assertSame($siteB->id, $employe->site_id);
        $this->assertSame(2, $employe->affectations()->count());
        $this->assertNotNull($employe->affectations()->where('site_id', $siteA->id)->first()->fin_at, 'l\'ancienne affectation reste dans l\'historique, close');
    }

    public function test_transferer_site_synchronise_lacces_applicatif_de_lutilisateur_lie(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $personne = Personne::factory()->create(['organization_id' => $org->id]);
        $employe = Employe::create([
            'organization_id' => $org->id, 'personne_id' => $personne->id, 'matricule' => 'EMP-200',
            'type_employe' => 'interne', 'statut' => 'actif',
        ]);
        $employeUser = User::factory()->create(['organization_id' => $org->id, 'personne_id' => $personne->id]);
        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt A', 'type' => 'depot']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt B', 'type' => 'depot']);
        $employeUser->sites()->attach($siteA->id, ['role' => 'employe', 'is_default' => true]);
        app(EmployeAffectationService::class)->definir($employe, $siteA, null, $admin);

        $this->actingAs($admin)
            ->patch("/backoffice/employes/{$employe->id}/transferer-site", ['site_id' => $siteB->id])
            ->assertRedirect();

        $employeUser->refresh();
        $this->assertFalse($employeUser->sites()->where('sites.id', $siteA->id)->exists());
        $this->assertTrue($employeUser->sites()->where('sites.id', $siteB->id)->exists());
    }

    public function test_transferer_site_conserve_la_fonction_actuelle(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $employe = $this->employe($org);
        $fonction = FonctionRh::create(['organization_id' => $org->id, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);
        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt A', 'type' => 'depot']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt B', 'type' => 'depot']);
        app(EmployeAffectationService::class)->definir($employe, $siteA, $fonction, $admin);

        $this->actingAs($admin)->patch("/backoffice/employes/{$employe->id}/transferer-site", ['site_id' => $siteB->id]);

        $employe->refresh();
        $this->assertSame($siteB->id, $employe->site_id);
        $this->assertSame($fonction->id, $employe->fonction_rh_id, 'transférer le site ne doit jamais effacer la fonction');
    }

    public function test_les_operations_historiques_dun_ancien_site_ne_sont_jamais_modifiees(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $employe = $this->employe($org);
        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt A', 'type' => 'depot']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt B', 'type' => 'depot']);
        app(EmployeAffectationService::class)->definir($employe, $siteA, null, $admin);
        $ancienneAffectation = $employe->affectationActive;
        $ancienneAffectationId = $ancienneAffectation->id;
        $ancienDebutAt = $ancienneAffectation->debut_at;

        $this->actingAs($admin)->patch("/backoffice/employes/{$employe->id}/transferer-site", ['site_id' => $siteB->id]);

        $ancienneAffectation->refresh();
        $this->assertSame($ancienneAffectationId, $ancienneAffectation->id);
        $this->assertTrue($ancienDebutAt->equalTo($ancienneAffectation->debut_at), 'le début de l\'ancienne affectation ne doit jamais être réécrit');
        $this->assertNotNull($ancienneAffectation->fin_at);
    }
}
