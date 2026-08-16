<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\ProprietaireInterneRegularisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Organization::proprietaire_interne_id — relation explicite par organisation qui remplace
 * l'ancien numéro de téléphone codé en dur (+224622602693) de
 * Proprietaire::interneParDefautId(). Couvre : régularisation des organisations existantes
 * (ProprietaireInterneRegularisationService), l'action de (re)configuration manuelle
 * (ProprietaireController::definirInterne), et l'isolation multi-tenant.
 */
class ProprietaireInterneTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['proprietaires.read', 'proprietaires.update']);
    }

    // ── ProprietaireInterneRegularisationService ─────────────────────────────

    public function test_regularise_une_organisation_qui_a_deja_le_proprietaire_magique_historique(): void
    {
        $moussa = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622602693',
        ]);

        $regularise = app(ProprietaireInterneRegularisationService::class)->regulariser($this->org);

        $this->assertTrue($regularise);
        $this->assertSame($moussa->id, $this->org->fresh()->proprietaire_interne_id);
    }

    public function test_regularise_une_organisation_sans_proprietaire_depuis_son_super_admin_unique(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
            'prenom' => 'Fatoumata',
            'telephone' => '+224621999999',
        ]);
        $admin->assignRole('super_admin');

        $this->assertSame(0, Proprietaire::where('organization_id', $this->org->id)->count());

        $regularise = app(ProprietaireInterneRegularisationService::class)->regulariser($this->org);

        $this->assertTrue($regularise);
        $org = $this->org->fresh();
        $this->assertNotNull($org->proprietaire_interne_id);
        $this->assertSame($admin->id, $org->proprietaireInterne->user_id);
        $this->assertSame('DIALLO', $org->proprietaireInterne->nom);
        $this->assertSame('+224621999999', $org->proprietaireInterne->telephone);
    }

    public function test_ne_regularise_pas_une_organisation_avec_plusieurs_super_admin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        User::factory()->create(['organization_id' => $this->org->id])->assignRole('super_admin');
        User::factory()->create(['organization_id' => $this->org->id])->assignRole('super_admin');

        $regularise = app(ProprietaireInterneRegularisationService::class)->regulariser($this->org);

        $this->assertFalse($regularise);
        $this->assertNull($this->org->fresh()->proprietaire_interne_id);
    }

    public function test_ne_regularise_pas_une_organisation_sans_super_admin_ni_proprietaire_magique(): void
    {
        $regularise = app(ProprietaireInterneRegularisationService::class)->regulariser($this->org);

        $this->assertFalse($regularise);
        $this->assertNull($this->org->fresh()->proprietaire_interne_id);
    }

    public function test_ne_touche_pas_une_organisation_deja_configuree(): void
    {
        $dejaConfigure = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->forceFill(['proprietaire_interne_id' => $dejaConfigure->id])->save();

        // Même si un propriétaire au téléphone magique existe aussi, ne doit jamais écraser
        // une configuration explicite déjà en place.
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622602693']);

        app(ProprietaireInterneRegularisationService::class)->regulariser($this->org);

        $this->assertSame($dejaConfigure->id, $this->org->fresh()->proprietaire_interne_id);
    }

    // ── ProprietaireController::definirInterne ───────────────────────────────

    public function test_definir_interne_rattache_le_proprietaire_a_lorganisation(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$proprietaire->id}/definir-interne")
            ->assertRedirect(route('proprietaires.show', $proprietaire));

        $this->assertSame($proprietaire->id, $this->org->fresh()->proprietaire_interne_id);
    }

    public function test_definir_interne_change_le_propriétaire_interne_precedent(): void
    {
        $ancien = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->forceFill(['proprietaire_interne_id' => $ancien->id])->save();

        $nouveau = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$nouveau->id}/definir-interne")
            ->assertRedirect(route('proprietaires.show', $nouveau));

        $this->assertSame($nouveau->id, $this->org->fresh()->proprietaire_interne_id);
    }

    public function test_definir_interne_refuse_pour_un_proprietaire_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaireAutreOrg = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$proprietaireAutreOrg->id}/definir-interne")
            ->assertForbidden();

        $this->assertNull($autreOrg->fresh()->proprietaire_interne_id);
    }

    // ── Isolation multi-tenant ────────────────────────────────────────────────

    public function test_proprietaire_interne_dune_organisation_nest_jamais_utilise_par_une_autre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->forceFill(['proprietaire_interne_id' => $proprietaire->id])->save();

        $autreOrg = Organization::factory()->create();

        $this->assertNull(Proprietaire::interneParDefautId($autreOrg->id));
        $this->assertSame($proprietaire->id, Proprietaire::interneParDefautId($this->org->id));
    }
}
