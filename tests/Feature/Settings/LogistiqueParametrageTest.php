<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Paramètres → Logistique (LogistiqueParametrageController). Déplacé le 07/09/2026 depuis
 * Paramètres → Ventes (cf. VenteParametrageController) : ce sont des réglages de workflow
 * logistique, pas des réglages de vente — seul l'écran d'édition change, les clés Parametre
 * restent identiques (`ventes_declencheur_commission_logistique`,
 * `logistique_approbation_reception_obligatoire`).
 *
 * Ne gère QUE le déclenchement du workflow — jamais le calcul, exclusivement piloté par
 * `Settings\CommissionRegleController` (Paramètres > Commissions > Transferts logistiques).
 * `montant_defaut_commission_logistique_par_pack` a été retiré le même jour (vestige mort,
 * plus lu par aucun code de génération depuis COMM-007) — cf.
 * test_montant_defaut_nest_plus_expose_ni_persistable ci-dessous, qui verrouille son absence.
 */
class LogistiqueParametrageTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthorizedUser(string $permission): User
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $adminRole = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user->assignRole($adminRole);

        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'declencheur_commission_logistique' => 'reception_effectuee',
            'approbation_reception_logistique_obligatoire' => true,
        ], $overrides);
    }

    public function test_edit_exposes_les_defauts_dune_organisation_neuve(): void
    {
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.logistique.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Logistique')
                ->where('declencheur_commission_logistique', 'reception_effectuee') // défaut historique
                ->where('approbation_reception_logistique_obligatoire', true) // défaut historique
            );
    }

    public function test_edit_respecte_les_parametres_deja_enregistres_explicitement(): void
    {
        $user = $this->createAuthorizedUser('parametres.read');

        Parametre::setApprobationReceptionLogistiqueObligatoire($user->organization_id, false);

        $this->actingAs($user)
            ->get(route('settings.logistique.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Logistique')
                ->where('approbation_reception_logistique_obligatoire', false)
            );
    }

    public function test_update_persists_declencheur_commission_logistique(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $this->validPayload([
                'declencheur_commission_logistique' => 'chargement_valide',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(
            'chargement_valide',
            Parametre::getDeclencheurCommissionLogistique($user->organization_id)->value,
        );
    }

    public function test_update_rejette_une_valeur_de_declencheur_logistique_invalide(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $this->validPayload([
                'declencheur_commission_logistique' => 'valeur_invalide',
            ]))
            ->assertSessionHasErrors('declencheur_commission_logistique');
    }

    public function test_update_persists_approbation_reception_logistique_obligatoire_as_false(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $this->validPayload([
                'approbation_reception_logistique_obligatoire' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(
            Parametre::isApprobationReceptionLogistiqueObligatoire($user->organization_id)
        );
    }

    public function test_update_requires_approbation_reception_logistique_obligatoire_field(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');

        $payload = $this->validPayload();
        unset($payload['approbation_reception_logistique_obligatoire']);

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $payload)
            ->assertSessionHasErrors('approbation_reception_logistique_obligatoire');
    }

    /**
     * Verrouille le retrait du 07/09/2026 : `montant_defaut_commission_logistique_par_pack` ne
     * doit plus être exposé par l'écran, et l'envoyer au endpoint update() ne doit ni planter
     * ni être silencieusement persisté (Laravel ignore un champ non listé dans validate()).
     */
    public function test_montant_defaut_nest_plus_expose_ni_persistable(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');
        Permission::findOrCreate('parametres.read', 'web');
        $user->givePermissionTo('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.logistique.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Logistique')
                ->missing('montant_defaut_commission_logistique_par_pack')
            );

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $this->validPayload([
                'montant_defaut_commission_logistique_par_pack' => 999,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(Parametre::query()
            ->where('organization_id', $user->organization_id)
            ->where('cle', 'ventes_montant_defaut_commission_logistique_par_pack')
            ->exists());
    }

    public function test_edit_expose_les_sites_de_lorganisation_avec_leur_derogation(): void
    {
        $user = $this->createAuthorizedUser('parametres.read');

        $site = Site::factory()->create(['organization_id' => $user->organization_id, 'nom' => 'Kouria']);
        $site->update(['approbation_reception_logistique_obligatoire' => false]);

        // Site d'une AUTRE organisation : ne doit jamais apparaître dans la liste.
        Site::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.logistique.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Logistique')
                ->has('sites', 1)
                ->where('sites.0.id', $site->id)
                ->where('sites.0.approbation_reception_logistique_obligatoire', false)
            );
    }

    public function test_update_site_persiste_la_derogation(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');
        $site = Site::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->patch(route('settings.logistique.sites.update', $site), [
                'approbation_reception_logistique_obligatoire' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($site->fresh()->approbation_reception_logistique_obligatoire);
    }

    public function test_update_site_accepte_null_pour_revenir_a_lheritage(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');
        $site = Site::factory()->create([
            'organization_id' => $user->organization_id,
            'approbation_reception_logistique_obligatoire' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('settings.logistique.sites.update', $site), [
                'approbation_reception_logistique_obligatoire' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($site->fresh()->approbation_reception_logistique_obligatoire);
    }

    /** Isolation multi-tenant : un site d'une autre organisation ne doit jamais être modifiable. */
    public function test_update_site_refuse_un_site_dune_autre_organisation(): void
    {
        $user = $this->createAuthorizedUser('parametres.update');
        $siteAutreOrg = Site::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.logistique.sites.update', $siteAutreOrg), [
                'approbation_reception_logistique_obligatoire' => false,
            ])
            ->assertForbidden();

        $this->assertNull($siteAutreOrg->fresh()->approbation_reception_logistique_obligatoire);
    }

    public function test_update_site_refuse_sans_permission_parametres_update(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->patch(route('settings.logistique.sites.update', $site), [
                'approbation_reception_logistique_obligatoire' => false,
            ])
            ->assertForbidden();
    }

    public function test_edit_refuse_sans_permission_parametres_read(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->get(route('settings.logistique.edit'))
            ->assertForbidden();
    }

    public function test_update_refuse_sans_permission_parametres_update(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->put(route('settings.logistique.update'), $this->validPayload())
            ->assertForbidden();
    }
}
