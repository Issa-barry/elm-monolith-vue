<?php

namespace Tests\Feature;

use App\Models\DroitAjustementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
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
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt Central',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Pack eau 1.5L',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'materiel')->value('id'),
            'statut' => 'actif',
            'prix_achat' => 500,
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->produit->variantePrincipale()->first()->id,
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

    /**
     * Depuis le 2026-09-06, admin_entreprise ne bypasse plus DroitAjustementStockService — il a
     * besoin d'une ligne DroitAjustementStock comme n'importe quel rôle. C'est exactement ce que
     * InstallationService::install() (et la migration de backfill pour les organisations
     * existantes) provisionne par défaut pour toute organisation réelle : ce test reproduit donc
     * cette continuité plutôt que de tester un bypass qui n'existe plus.
     */
    public function test_admin_avec_droit_configure_peut_ajuster_stock(): void
    {
        $this->droitToutes(role: 'admin_entreprise');

        $this->actingAs($this->adminUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();
    }

    public function test_admin_sans_droit_configure_recoit_403(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('produits.ajuster-stock', $this->produit), [
                'site_id' => $this->site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertForbidden();
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

    /** Cf. test_admin_avec_droit_configure_peut_ajuster_stock — même besoin de continuité. */
    public function test_admin_avec_droit_configure_recoit_can_ajuster_true_sur_la_page_index(): void
    {
        $this->droitToutes(role: 'admin_entreprise');

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

    /**
     * `can_ajuster_stock`/`can_augmenter_stock`/`can_diminuer_stock` sont des capacités
     * GÉNÉRALES ("ce rôle a-t-il un droit actif quelque part dans l'organisation"), jamais
     * spécifiques au site par défaut de l'acteur (décision 2026-09-07, cf. docblock de
     * DroitAjustementStockService) — `sites_autorises` porte seul l'information de périmètre :
     * le manager a bien un droit (augmenter uniquement, sur Dabompa), donc `can_ajuster_stock`
     * et `can_augmenter_stock` sont vrais et `sites_autorises` liste Dabompa, même si son propre
     * site par défaut (Lansanaya) n'y figure pas. Toute tentative d'ajustement effective SUR
     * Lansanaya reste refusée (cf. test_manager_sur_site_non_dans_perimetre_recoit_403).
     */
    public function test_manager_avec_perimetre_ailleurs_voit_son_vrai_perimetre_dans_les_props(): void
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
                ->where('can_ajuster_stock', true)
                ->where('can_augmenter_stock', true)
                ->where('can_diminuer_stock', false)
                ->has('sites_autorises', 1)
                ->where('sites_autorises.0.id', $siteAutorise->id)
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
