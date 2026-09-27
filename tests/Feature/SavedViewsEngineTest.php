<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Moteur commun « Mes vues », éprouvé sur le scope produits (cf. docs/filters.md). */
class SavedViewsEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Site $matoto;

    private Site $dixinn;

    private User $auteur;

    private User $collegue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->organization->id);
        $this->matoto = Site::factory()->create(['organization_id' => $this->organization->id, 'nom' => 'Matoto']);
        $this->dixinn = Site::factory()->create(['organization_id' => $this->organization->id, 'nom' => 'Dixinn']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->auteur = $this->utilisateur($this->matoto, ['produits.read', 'produits.update']);
        $this->collegue = $this->utilisateur($this->dixinn, ['produits.read']);
    }

    private function utilisateur(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function creer(User $user, string $name, array $filters, string $visibility = 'personal', bool $default = false): string
    {
        return $this->actingAs($user)->postJson(route('saved-filters.store', 'produits'), [
            'name' => $name, 'visibility' => $visibility, 'filters' => $filters, 'is_default' => $default,
        ])->assertCreated()->json('id');
    }

    public function test_plusieurs_vues_personnelles_restent_invisibles_des_autres(): void
    {
        $this->creer($this->auteur, 'Actifs', ['statut' => 'actif']);
        $this->creer($this->auteur, 'Stock faible', ['stock' => 'stock_faible']);

        $this->actingAs($this->auteur)->getJson(route('saved-filters.index', 'produits'))->assertJsonCount(2, 'views');
        $this->actingAs($this->collegue)->getJson(route('saved-filters.index', 'produits'))->assertJsonCount(0, 'views');
    }

    public function test_une_vue_partagee_est_visible_mais_non_modifiable_par_un_collegue(): void
    {
        $id = $this->creer($this->auteur, 'Bouteilles', ['search' => 'Bouteille'], 'shared');

        $this->actingAs($this->collegue)->getJson(route('saved-filters.index', 'produits'))
            ->assertJsonPath('views.0.id', $id)
            ->assertJsonPath('views.0.can_manage', false);
        $this->actingAs($this->collegue)->patchJson(route('saved-filters.update', ['produits', $id]), ['name' => 'Pirate', 'visibility' => 'personal'])->assertNotFound();
        $this->actingAs($this->collegue)->deleteJson(route('saved-filters.destroy', ['produits', $id]))->assertNotFound();
    }

    public function test_renommer_ne_change_pas_les_criteres_et_supprimer_retire_la_vue(): void
    {
        $id = $this->creer($this->auteur, 'Actifs', ['statut' => 'actif']);

        $this->actingAs($this->auteur)->patchJson(route('saved-filters.update', ['produits', $id]), [
            'name' => 'Produits actifs', 'visibility' => 'personal', 'filters' => ['statut' => 'archive'],
        ])->assertOk()->assertJsonPath('name', 'Produits actifs')->assertJsonPath('filters.statut', 'actif');

        $this->actingAs($this->auteur)->deleteJson(route('saved-filters.destroy', ['produits', $id]))->assertOk();
        $this->actingAs($this->auteur)->getJson(route('saved-filters.index', 'produits'))->assertJsonCount(0, 'views');
    }

    public function test_la_vue_par_defaut_est_propre_a_chaque_utilisateur_et_modifiable(): void
    {
        $partagee = $this->creer($this->auteur, 'Bouteilles', ['search' => 'Bouteille'], 'shared', true);
        $autre = $this->creer($this->auteur, 'Actifs', ['statut' => 'actif']);

        $this->actingAs($this->collegue)->getJson(route('saved-filters.index', 'produits'))->assertJsonPath('default_id', null);
        $this->actingAs($this->collegue)->putJson(route('saved-filters.default', 'produits'), ['id' => $partagee])->assertOk();

        $this->actingAs($this->auteur)->putJson(route('saved-filters.default', 'produits'), ['id' => $autre])->assertOk();
        $this->actingAs($this->auteur)->getJson(route('saved-filters.index', 'produits'))->assertJsonPath('default_id', $autre);
        $this->actingAs($this->collegue)->getJson(route('saved-filters.index', 'produits'))->assertJsonPath('default_id', $partagee);

        $this->actingAs($this->auteur)->get(route('produits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('saved_view.id', $autre)->where('filters.statut', 'actif'));
    }

    public function test_une_vue_mes_agences_sadapte_a_chaque_utilisateur(): void
    {
        $id = $this->creer($this->auteur, 'Mon agence', ['site_scope' => 'mine', 'statut' => 'actif'], 'shared');

        $this->actingAs($this->auteur)->get(route('produits.index', ['saved_view' => $id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('saved_view.id', $id)->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->matoto->id]));
        $this->actingAs($this->collegue)->get(route('produits.index', ['saved_view' => $id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('saved_view.id', $id)->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->dixinn->id]));
    }

    public function test_une_vue_sans_critere_est_refusee(): void
    {
        $this->actingAs($this->auteur)->postJson(route('saved-filters.store', 'produits'), ['name' => 'Vide', 'visibility' => 'personal', 'filters' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('filters');
    }
}
