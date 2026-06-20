<?php

namespace Tests\Feature;

use App\Models\DroitAjustementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProduitAjustementScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt Central',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Pack eau 1.5L',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);

        ProduitStock::create([
            'organization_id' => $this->org->id,
            'produit_id' => $this->produit->id,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
        ]);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('produits.read');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function managerUser(): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo('produits.read');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function droitToutes(string $role = 'manager', bool $augmenter = true, bool $diminuer = true): void
    {
        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => $role,
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_augmenter' => $augmenter,
            'peut_diminuer' => $diminuer,
        ]);
    }

    // ── API : accès / blocage ─────────────────────────────────────────────────

    public function test_admin_peut_ajuster_stock_sans_droit_configure(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();
    }

    public function test_manager_sans_droit_recoit_403(): void
    {
        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertForbidden();
    }

    public function test_manager_avec_droit_augmenter_peut_augmenter(): void
    {
        $this->droitToutes(augmenter: true, diminuer: false);

        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();
    }

    public function test_manager_avec_droit_augmenter_uniquement_ne_peut_pas_diminuer(): void
    {
        $this->droitToutes(augmenter: true, diminuer: false);

        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'diminuer' => 5,
                'motif_type' => 'perte',
            ])
            ->assertForbidden();
    }

    public function test_manager_avec_droit_diminuer_uniquement_ne_peut_pas_augmenter(): void
    {
        $this->droitToutes(augmenter: false, diminuer: true);

        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertForbidden();
    }

    public function test_manager_avec_les_deux_droits_peut_augmenter_et_diminuer(): void
    {
        $this->droitToutes(augmenter: true, diminuer: true);
        $user = $this->managerUser();

        $this->actingAs($user)
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'diminuer' => 5,
                'motif_type' => 'perte',
            ])
            ->assertRedirect();
    }

    public function test_manager_agences_selectionnees_recoit_403_sur_site_non_autorise(): void
    {
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence B',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$autreSite->id],
            'peut_augmenter' => true,
            'peut_diminuer' => true,
        ]);

        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertForbidden();
    }

    // ── Props Inertia ─────────────────────────────────────────────────────────

    public function test_non_autorise_recoit_can_ajuster_false_sur_la_page_index(): void
    {
        $this->actingAs($this->managerUser())
            ->get(route('produits.index'))
            ->assertInertia(fn ($page) => $page
                ->where('can_ajuster_stock', false)
                ->where('can_augmenter_stock', false)
                ->where('can_diminuer_stock', false)
                ->where('sites_autorises', [])
            );
    }

    public function test_admin_recoit_can_ajuster_true_sur_la_page_index(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('produits.index'))
            ->assertInertia(fn ($page) => $page
                ->where('can_ajuster_stock', true)
                ->where('can_augmenter_stock', true)
                ->where('can_diminuer_stock', true)
            );
    }

    public function test_manager_avec_augmenter_uniquement_recoit_les_bons_flags(): void
    {
        $this->droitToutes(augmenter: true, diminuer: false);

        $this->actingAs($this->managerUser())
            ->get(route('produits.index'))
            ->assertInertia(fn ($page) => $page
                ->where('can_ajuster_stock', true)
                ->where('can_augmenter_stock', true)
                ->where('can_diminuer_stock', false)
            );
    }

    public function test_manager_sur_site_non_dans_perimetre_recoit_403(): void
    {
        $siteAutorise = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dabompa',
            'type' => 'depot',
            'localisation' => 'Dabompa',
        ]);

        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$siteAutorise->id],
            'peut_augmenter' => true,
            'peut_diminuer' => false,
        ]);

        // Le manager est affecté à $this->site (Lansanaya), pas à Dabompa
        $this->actingAs($this->managerUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertForbidden();
    }

    public function test_manager_sur_site_non_dans_perimetre_recoit_can_ajuster_false(): void
    {
        $siteAutorise = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dabompa',
            'type' => 'depot',
            'localisation' => 'Dabompa',
        ]);

        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$siteAutorise->id],
            'peut_augmenter' => true,
            'peut_diminuer' => false,
        ]);

        // Le manager est à $this->site (Lansanaya), hors du périmètre Dabompa
        $this->actingAs($this->managerUser())
            ->get(route('produits.index'))
            ->assertInertia(fn ($page) => $page
                ->where('can_ajuster_stock', false)
                ->where('can_augmenter_stock', false)
                ->where('can_diminuer_stock', false)
                ->where('sites_autorises', [])
            );
    }

    public function test_manager_autorise_recoit_uniquement_ses_sites_dans_les_props(): void
    {
        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$this->site->id],
            'peut_augmenter' => true,
            'peut_diminuer' => false,
        ]);

        $this->actingAs($this->managerUser())
            ->get(route('produits.index'))
            ->assertInertia(fn ($page) => $page
                ->where('can_ajuster_stock', true)
                ->has('sites_autorises', 1)
                ->where('sites_autorises.0.id', $this->site->id)
            );
    }
}
