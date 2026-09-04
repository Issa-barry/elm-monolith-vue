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

    public function test_update_applies_unit_price_permission_by_role_selection(): void
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 200,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $commercialeRole = Role::query()->where('name', 'commerciale')->firstOrFail();
        $managerRole = Role::query()->where('name', 'manager')->firstOrFail();

        $this->assertTrue($commercialeRole->hasPermissionTo('ventes.prix.update'));
        $this->assertFalse($managerRole->hasPermissionTo('ventes.prix.update'));
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 200,
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 200,
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
     * commission logistique à la réception (comportement historique, inchangé), contrôle des
     * impayés actif avec seuil 0.
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
                ->where('declencheur_commission_logistique', 'reception_effectuee')
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

    public function test_update_persists_declencheurs_commission(): void
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
                'declencheur_commission_logistique' => 'chargement_valide',
                'montant_defaut_commission_logistique_par_pack' => 200,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(
            'facture_encaissee',
            Parametre::getDeclencheurCommissionVente($user->organization_id)->value,
        );
        $this->assertEquals(
            'chargement_valide',
            Parametre::getDeclencheurCommissionLogistique($user->organization_id)->value,
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 200,
            ])
            ->assertSessionHasErrors('declencheur_commission_vente');
    }

    public function test_update_rejette_une_valeur_de_declencheur_logistique_invalide(): void
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
                'declencheur_commission_vente' => 'chargement_valide',
                'declencheur_commission_logistique' => 'valeur_invalide',
                'montant_defaut_commission_logistique_par_pack' => 200,
            ])
            ->assertSessionHasErrors('declencheur_commission_logistique');
    }

    public function test_edit_expose_le_montant_defaut_commission_logistique_par_pack(): void
    {
        $this->createRoles();
        $user = $this->createAuthorizedUser('parametres.read');

        $this->actingAs($user)
            ->get(route('settings.ventes.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ventes')
                ->where('montant_defaut_commission_logistique_par_pack', 200) // défaut historique
            );
    }

    public function test_update_persists_le_montant_defaut_commission_logistique_par_pack(): void
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 350,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(
            350,
            Parametre::getMontantDefautCommissionLogistiquePack($user->organization_id),
        );
    }

    public function test_update_rejette_un_montant_logistique_invalide(): void
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
                'declencheur_commission_logistique' => 'reception_effectuee',
                'montant_defaut_commission_logistique_par_pack' => 0,
            ])
            ->assertSessionHasErrors('montant_defaut_commission_logistique_par_pack');
    }
}
