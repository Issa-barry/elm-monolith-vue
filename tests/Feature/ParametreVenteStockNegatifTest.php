<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le paramètre « Autoriser les ventes sans stock disponible » (Paramètres > Paramètres
 * produits, cf. StockAjustementController) est une politique GLOBALE d'organisation
 * (Parametre::CLE_VENTES_AUTORISER_STOCK_NEGATIF) — jamais par produit (décision du
 * 23/08/2026). Cette page porte aussi une carte indépendante « Droits d'ajustement de stock »
 * (config des rôles) : les deux cartes doivent pouvoir être enregistrées séparément, sans
 * qu'enregistrer l'une n'écrase l'autre.
 */
class ParametreVenteStockNegatifTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.read', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo(['parametres.update', 'parametres.read']);

        $site = Site::factory()->for($this->org)->create();
        $this->admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    public function test_valeur_par_defaut_dune_nouvelle_organisation_est_non(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/produits')
            ->assertInertia(fn ($page) => $page
                ->component('settings/ProduitParametrage')
                ->where('autorise_vente_stock_negatif', false)
            );

        $this->assertFalse(Parametre::isVentesAutoriseesSansStock($this->org->id));
    }

    public function test_admin_peut_activer_le_parametre(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => true])
            ->assertRedirect();

        $this->assertTrue(Parametre::isVentesAutoriseesSansStock($this->org->id));
    }

    public function test_admin_peut_desactiver_le_parametre_apres_activation(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->actingAs($this->admin)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => false])
            ->assertRedirect();

        $this->assertFalse(Parametre::isVentesAutoriseesSansStock($this->org->id));
    }

    /** La valeur enregistrée doit être relue identique à un rechargement ultérieur de la page. */
    public function test_la_valeur_persiste_apres_rechargement(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => true])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get('/settings/produits')
            ->assertInertia(fn ($page) => $page
                ->where('autorise_vente_stock_negatif', true)
            );
    }

    public function test_utilisateur_sans_permission_parametres_update_ne_peut_pas_modifier(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);
        $user->assignRole('commerciale');

        $this->actingAs($user)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => true])
            ->assertForbidden();

        $this->assertFalse(Parametre::isVentesAutoriseesSansStock($this->org->id));
    }

    public function test_utilisateur_sans_permission_parametres_read_ne_peut_pas_consulter(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);
        $user->assignRole('commerciale');

        $this->actingAs($user)
            ->get('/settings/produits')
            ->assertForbidden();
    }

    /**
     * Les deux cartes de la page (politique de vente / droits d'ajustement) doivent rester
     * indépendantes : enregistrer l'une ne doit jamais réinitialiser ou toucher l'autre.
     */
    public function test_enregistrer_le_parametre_de_vente_ne_touche_pas_aux_droits_dajustement(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/produits', [
                'config' => [[
                    'role_name' => 'manager',
                    'peut_augmenter' => true,
                    'peut_diminuer' => false,
                    'perimetre' => 'toutes_agences',
                    'sites' => [],
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_ajustement_stocks', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_augmenter' => true,
        ]);

        $this->actingAs($this->admin)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => true])
            ->assertRedirect();

        // Le droit précédemment configuré doit rester intact.
        $this->assertDatabaseHas('droit_ajustement_stocks', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_augmenter' => true,
        ]);
        $this->assertTrue(Parametre::isVentesAutoriseesSansStock($this->org->id));
    }

    public function test_isolation_multi_organisation_du_parametre(): void
    {
        $orgB = Organization::factory()->create();
        $adminB = User::factory()->create(['organization_id' => $orgB->id]);
        $adminB->assignRole('admin_entreprise');
        $adminB->givePermissionTo(['parametres.update', 'parametres.read']);
        $siteB = Site::factory()->for($orgB)->create();
        $adminB->sites()->attach($siteB->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($this->admin)
            ->put('/settings/produits', ['autorise_vente_stock_negatif' => true])
            ->assertRedirect();

        $this->assertTrue(Parametre::isVentesAutoriseesSansStock($this->org->id));
        $this->assertFalse(Parametre::isVentesAutoriseesSansStock($orgB->id));

        $this->actingAs($adminB)
            ->get('/settings/produits')
            ->assertInertia(fn ($page) => $page->where('autorise_vente_stock_negatif', false));
    }
}
