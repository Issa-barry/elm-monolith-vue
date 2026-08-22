<?php

namespace Tests\Unit;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rh\AccountValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountValidationService $service;

    private Organization $org;

    private User $superAdmin;

    private User $adminEntreprise;

    private Site $site;

    private FonctionRh $fonction;

    private Role $roleOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountValidationService::class);
        $this->org = Organization::factory()->create();
        $this->site = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->fonction = FonctionRh::create([
            'organization_id' => $this->org->id,
            'libelle' => 'Gérant de dépôt',
            'code' => 'GDE',
            'is_active' => true,
        ]);
        $this->roleOrg = Role::query()->create([
            'name' => 'gerant_depot', 'guard_name' => 'web', 'label' => 'Gérant de dépôt',
            'organization_id' => $this->org->id,
        ]);

        Role::query()->create(['name' => 'super_admin', 'guard_name' => 'web', 'label' => 'Super admin']);

        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->superAdmin->assignRole('super_admin');
        $this->adminEntreprise = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function pendingUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'status' => User::STATUS_PENDING_VALIDATION,
            'is_active' => false,
        ]);
    }

    public function test_valide_un_membre_du_personnel_et_cree_sa_fiche_employe(): void
    {
        $user = $this->pendingUser();

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => true,
            'role_id' => $this->roleOrg->id,
            'site_id' => $this->site->id,
            'fonction_rh_id' => $this->fonction->id,
            'type_employe' => 'interne',
            'statut' => 'actif',
        ], $this->adminEntreprise);

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertTrue($user->hasRole('gerant_depot'));

        $employe = Employe::where('organization_id', $this->org->id)->where('personne_id', $user->personne_id)->first();
        $this->assertNotNull($employe);
        $this->assertSame($this->fonction->id, $employe->fonction_rh_id);
        $this->assertSame($this->site->id, $employe->site_id);
    }

    public function test_valide_un_utilisateur_externe_sans_creer_de_fiche_employe(): void
    {
        $user = $this->pendingUser();

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => false,
            'role_id' => $this->roleOrg->id,
            'site_id' => $this->site->id,
        ], $this->adminEntreprise);

        $user->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNull(Employe::where('organization_id', $this->org->id)->where('personne_id', $user->personne_id)->first());
    }

    public function test_rattache_une_fiche_employe_deja_existante_pour_la_meme_personne_au_lieu_de_la_dupliquer(): void
    {
        $user = $this->pendingUser();
        // Simule un Employe déjà créé côté RH avant l'invitation (même Personne). Employe::create()
        // directement plutôt que Employe::factory()->create(['personne_id' => ...]) : la factory a
        // un bug de ré-entrance pré-existant (hors périmètre de cette mission) qui écrase
        // silencieusement un personne_id explicite lorsque Laravel réappelle create() en interne
        // via state()->create([]) — vérifié en isolation, à signaler séparément.
        $employeExistant = Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $user->personne_id,
            'matricule' => 'EMP-EXIST',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => true,
            'role_id' => $this->roleOrg->id,
            'site_id' => $this->site->id,
            'fonction_rh_id' => $this->fonction->id,
            'type_employe' => 'interne',
            'statut' => 'actif',
        ], $this->adminEntreprise);

        $this->assertSame(1, Employe::where('organization_id', $this->org->id)->where('personne_id', $user->personne_id)->count(), 'aucun doublon');
        $this->assertSame($employeExistant->id, Employe::where('personne_id', $user->personne_id)->first()->id);
        $this->assertSame($this->fonction->id, $employeExistant->fresh()->fonction_rh_id);
    }

    public function test_super_admin_nest_attribuable_que_par_un_acteur_deja_super_admin(): void
    {
        $user = $this->pendingUser();

        $this->expectException(HttpException::class);

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => false,
            'role_id' => Role::where('name', 'super_admin')->value('id'),
            'site_id' => $this->site->id,
        ], $this->adminEntreprise);
    }

    public function test_super_admin_peut_attribuer_super_admin(): void
    {
        $user = $this->pendingUser();

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => false,
            'role_id' => Role::where('name', 'super_admin')->value('id'),
            'site_id' => $this->site->id,
        ], $this->superAdmin);

        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    public function test_refuse_un_role_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $roleAutreOrg = Role::query()->create(['name' => 'role_autre_org', 'guard_name' => 'web', 'organization_id' => $autreOrg->id]);
        $user = $this->pendingUser();

        $this->expectException(HttpException::class);

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => false,
            'role_id' => $roleAutreOrg->id,
            'site_id' => $this->site->id,
        ], $this->adminEntreprise);
    }

    public function test_refuse_de_valider_un_compte_deja_traite(): void
    {
        $user = $this->pendingUser();
        $user->update(['status' => User::STATUS_ACTIVE, 'is_active' => true]);

        // abort_unless(..., 422, ...) — un 422 brut, pas une ValidationException (qui
        // redirigerait sur une requête web standard) : comportement historique préservé,
        // cf. AccountValidationTest::test_cannot_validate_an_already_active_account (pré-existant).
        $this->expectException(HttpException::class);

        $this->service->valider($user, [
            'is_staff_avec_fiche_employe' => false,
            'role_id' => $this->roleOrg->id,
            'site_id' => $this->site->id,
        ], $this->adminEntreprise);
    }

    public function test_aucune_modification_partielle_si_la_transaction_echoue(): void
    {
        $user = $this->pendingUser();

        try {
            $this->service->valider($user, [
                'is_staff_avec_fiche_employe' => true,
                'role_id' => $this->roleOrg->id,
                'site_id' => $this->site->id,
                // fonction_rh_id manquant/invalide : provoque une ValidationException APRÈS
                // le syncRoles() théorique — vérifie que le rollback annule bien tout, y
                // compris le changement de rôle.
                'fonction_rh_id' => 'id-inexistant',
                'type_employe' => 'interne',
                'statut' => 'actif',
            ], $this->adminEntreprise);
            $this->fail('une exception était attendue');
        } catch (ValidationException) {
            // attendu
        }

        $user->refresh();
        $this->assertTrue($user->isPendingValidation(), 'le compte doit rester en attente, rollback complet');
        $this->assertFalse($user->hasRole('gerant_depot'), 'le rôle ne doit pas avoir été appliqué');
        $this->assertNull(Employe::where('organization_id', $this->org->id)->where('personne_id', $user->personne_id)->first());
    }
}
