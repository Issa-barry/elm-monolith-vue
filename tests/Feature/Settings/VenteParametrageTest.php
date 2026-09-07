<?php

namespace Tests\Feature\Settings;

use App\Enums\DeclencheurCommissionVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VenteParametrageTest extends TestCase
{
    use RefreshDatabase;

    private function createRoles(): void
    {
        foreach (['super_admin', 'admin_entreprise', 'manager', 'commerciale'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

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

    public function test_edit_exposes_price_permission_flags_per_role(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->has('roles')
                ->where('roles', fn ($roles) => collect($roles)->every(
                    fn (array $role) => array_key_exists('can_update_prix_unitaire', $role)
                ))
            );
    }

    /**
     * `commerciale`/`manager` sont des rôles système partagés par toutes les organisations
     * (organization_id null) — ce test cible donc désormais un rôle métier propre à
     * l'organisation pour vérifier le mécanisme de sélection, plutôt qu'un rôle système (cf.
     * test_update_does_not_change_permissions_of_a_system_role_for_non_super_admin ci-dessous
     * pour la garantie inverse).
     */
    public function test_update_applies_unit_price_permission_by_role_selection(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');
        Permission::findOrCreate('parametres.read', 'web');
        $user->givePermissionTo('parametres.read');

        // Créées explicitement ici (état "déjà déployé") pour ne pas dépendre du bootstrap
        // ponctuel ensureSalesPermissionsExist() (qui n'accorde par défaut qu'à la première
        // création de ces permissions, jamais ensuite) — état réaliste d'une organisation qui
        // n'en est pas à sa toute première configuration.
        Permission::findOrCreate('ventes.prix.update', 'web');
        Permission::findOrCreate('ventes.qte.update', 'web');

        $orgRole = Role::create(['name' => 'chef_agence', 'label' => 'Chef agence', 'guard_name' => 'web', 'organization_id' => $user->organization_id]);
        $managerRole = Role::query()->where('name', 'manager')->firstOrFail();
        $managerHadPermissionBefore = $managerRole->hasPermissionTo('ventes.prix.update');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [$orgRole->name],
                'autoriser_saisie_dessous_qte_max' => true,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'chargement_valide',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($orgRole->fresh()->hasPermissionTo('ventes.prix.update'));
        // 'manager' est un rôle système (partagé, non sélectionné ici) : son état ne doit pas
        // bouger, quel qu'il soit — ni forcé à false (ancien comportement, désormais dangereux
        // en cross-tenant) ni affecté par la sélection d'un autre rôle.
        $this->assertSame($managerHadPermissionBefore, $managerRole->fresh()->hasPermissionTo('ventes.prix.update'));
    }

    /**
     * Verrou central de la refonte rôles/permissions (2026-09-06) : `commerciale` est un rôle
     * système partagé par TOUTES les organisations — avant ce verrou, cocher "Commerciale"
     * depuis l'écran de paramétrage ventes d'UNE organisation modifiait silencieusement le
     * comportement de `commerciale` pour toutes les autres. Un admin_entreprise ne peut donc
     * plus faire varier ses permissions depuis cet écran ; seul un super_admin le peut.
     */
    public function test_update_does_not_change_permissions_of_a_system_role_for_non_super_admin(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');
        Permission::findOrCreate('parametres.read', 'web');
        $user->givePermissionTo('parametres.read');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => ['commerciale'],
                'autoriser_saisie_dessous_qte_max' => true,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'chargement_valide',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $commercialeRole = Role::query()->where('name', 'commerciale')->firstOrFail();
        $this->assertFalse($commercialeRole->hasPermissionTo('ventes.prix.update'));
    }

    public function test_edit_exposes_autoriser_saisie_dessous_qte_max_prop(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->has('autoriser_saisie_dessous_qte_max')
                ->where('autoriser_saisie_dessous_qte_max', true) // défaut = true
            );
    }

    public function test_update_persists_autoriser_saisie_dessous_qte_max_as_false(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [],
                'autoriser_saisie_dessous_qte_max' => false,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'chargement_valide',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(
            Parametre::isVentesAutorisationSaisieDessousQteMax($user->organization_id)
        );
    }

    public function test_update_persists_autoriser_saisie_dessous_qte_max_as_true(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');

        // D'abord désactiver pour s'assurer qu'on repart d'un état connu
        Parametre::setVentesAutorisationSaisieDessousQteMax($user->organization_id, false);

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [],
                'autoriser_saisie_dessous_qte_max' => true,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'chargement_valide',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            Parametre::isVentesAutorisationSaisieDessousQteMax($user->organization_id)
        );
    }

    public function test_update_requires_autoriser_saisie_dessous_qte_max_field(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [],
                // autoriser_saisie_dessous_qte_max absent intentionnellement
            ])
            ->assertSessionHasErrors('autoriser_saisie_dessous_qte_max');
    }

    /**
     * Aucun Parametre::set... appelé : ces valeurs sont les défauts d'une organisation neuve
     * (décision produit du 18/08/2026) — commission vente à l'encaissement de la facture,
     * contrôle des impayés actif avec seuil 0. Le déclencheur logistique a son propre défaut,
     * couvert par LogistiqueParametrageTest (page déplacée le 07/09/2026).
     */
    public function test_edit_exposes_les_nouveaux_defauts_dune_organisation_neuve(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->where('declencheur_commission_vente', 'facture_encaissee')
                ->where('controle_impayes_actif', true)
                ->where('seuil_impayes_max', 0)
            );
    }

    /**
     * Une organisation ayant déjà explicitement enregistré ses paramètres (peu importe la
     * valeur) ne doit jamais être écrasée par le nouveau fallback — cf. Parametre::get(), qui
     * ne lit le fallback qu'en l'absence de ligne réelle en base.
     */
    public function test_edit_respecte_les_parametres_deja_enregistres_explicitement(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        Parametre::setVentesControleImpayes($user->organization_id, false, 500_000);
        Parametre::setDeclencheurCommissionVente($user->organization_id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->where('declencheur_commission_vente', 'chargement_valide')
                ->where('controle_impayes_actif', false)
                ->where('seuil_impayes_max', 500_000)
            );
    }

    public function test_update_persists_declencheur_commission_vente(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [],
                'autoriser_saisie_dessous_qte_max' => true,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'facture_encaissee',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(
            'facture_encaissee',
            Parametre::getDeclencheurCommissionVente($user->organization_id)->value,
        );
    }

    public function test_update_rejette_une_valeur_de_declencheur_vente_invalide(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.ventes.update'), [
                'quantity_edit_role_names' => [],
                'price_edit_role_names' => [],
                'autoriser_saisie_dessous_qte_max' => true,
                'controle_impayes_actif' => false,
                'seuil_impayes_max' => 0,
                'declencheur_commission_vente' => 'valeur_invalide',
            ])
            ->assertSessionHasErrors('declencheur_commission_vente');
    }
}
