<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class MouvementFondsSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $origine;

    private Site $destination;

    private CompteTresorerie $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.update']);
        $this->origine = $this->user->sites()->first();
        $this->destination = Site::factory()->create(['organization_id' => $this->org->id]);
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisse = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->origine->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse origine',
        ]);
        $this->mouvement('MVT-CIBLE', 250_000, 'envoye');
        $this->mouvement('MVT-AUTRE', 900_000, 'recu');
    }

    private function mouvement(string $reference, int $montant, string $statut, array $extra = []): MouvementFonds
    {
        return MouvementFonds::create([
            'organization_id' => $this->org->id,
            'reference' => $reference,
            'nature' => 'inter_sites',
            'site_origine_id' => $this->origine->id,
            'site_destination_id' => $this->destination->id,
            'compte_tresorerie_origine_id' => $this->caisse->id,
            'montant' => $montant,
            'statut' => $statut,
            'created_by' => $this->user->id,
            ...$extra,
        ]);
    }

    private function storeView(array $filters, array $extra = [])
    {
        return $this->actingAs($this->user)->postJson(route('saved-filters.store', 'mouvements-fonds'), [
            'name' => 'Vue mouvements',
            'visibility' => 'personal',
            'filters' => $filters,
            ...$extra,
        ]);
    }

    public function test_la_vue_applique_tous_les_criteres_au_serveur(): void
    {
        $filters = [
            'statut' => 'envoye',
            'search' => 'CIBLE',
            'nature' => 'inter_sites',
            'site_ids' => [$this->origine->id],
            'site_origine_id' => $this->origine->id,
            'site_destination_id' => $this->destination->id,
            'caisse_id' => $this->caisse->id,
            'caisse_role' => 'origine',
            'montant_min' => '200000',
            'montant_max' => '300000',
        ];
        $id = $this->storeView($filters)->assertCreated()->json('id');

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index', [
            'saved_view' => $id,
            'search' => 'AUTRE',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('saved_view.id', $id)
            ->where('filters', $filters)
            ->has('mouvements.data', 1)
            ->where('mouvements.data.0.reference', 'MVT-CIBLE'));
    }

    public function test_la_vue_par_defaut_ne_remplace_pas_les_filtres_manuels_ou_la_reinitialisation(): void
    {
        $this->storeView(['statut' => 'envoye'], ['is_default' => true])->assertCreated();
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view.name', 'Vue mouvements')->has('mouvements.data', 1));
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index', ['all' => '1']))
            ->assertInertia(fn (Assert $page) => $page->where('saved_view', null)->has('mouvements.data', 2));
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index', ['statut' => 'recu']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view', null)->has('mouvements.data', 1)->where('mouvements.data.0.reference', 'MVT-AUTRE'));
    }

    public function test_les_vues_des_supports_et_les_vues_privees_dautres_utilisateurs_sont_isolees(): void
    {
        $id = $this->storeView(['statut' => 'envoye'])->assertCreated()->json('id');
        $this->actingAs($this->user)->getJson(route('saved-filters.index', 'tresorerie-supports'))
            ->assertOk()->assertJsonCount(0, 'views');
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index', ['saved_view' => 'incorrect']))
            ->assertSessionHasErrors('saved_view');

        $autre = $this->makeUserWithPermissions($this->org, ['tresorerie.read']);
        $autre->sites()->attach($this->origine->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($autre)->getJson(route('saved-filters.index', 'mouvements-fonds'))
            ->assertOk()->assertJsonCount(0, 'views');
        $this->actingAs($autre)->get(route('comptabilite.tresorerie.mouvements.index', ['saved_view' => $id]))
            ->assertNotFound();
    }

    public function test_le_partage_et_la_lecture_sont_proteges_par_les_permissions_backend(): void
    {
        $this->user->revokePermissionTo('tresorerie.update');
        $this->storeView(['statut' => 'envoye'], ['visibility' => 'shared'])->assertForbidden();
        $this->actingAs($this->user)->getJson(route('saved-filters.index', 'mouvements-fonds'))
            ->assertOk()->assertJsonPath('can_share', false);
        $this->user->givePermissionTo('tresorerie.update');
        $this->storeView(['statut' => 'envoye'], ['visibility' => 'shared'])->assertCreated();
        $this->user->revokePermissionTo('tresorerie.read');
        $this->actingAs($this->user)->getJson(route('saved-filters.index', 'mouvements-fonds'))->assertForbidden();
        $this->storeView(['statut' => 'envoye'])->assertForbidden();
    }

    public function test_les_criteres_invalides_et_les_entites_dune_autre_organisation_sont_refuses(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $org->id]);
        $this->storeView(['statut' => 'inconnu', 'nature' => 'inconnue', 'caisse_role' => 'invalide', 'montant_min' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['filters.statut', 'filters.nature', 'filters.caisse_role', 'filters.montant_min']);
        $this->storeView(['site_origine_id' => $site->id, 'site_destination_id' => $site->id, 'site_ids' => [$site->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['filters.site_origine_id', 'filters.site_destination_id', 'filters.site_ids.0']);
        $this->storeView(['stock_statut' => 'rupture'])->assertUnprocessable()->assertJsonValidationErrors('filters');
        $autre = User::factory()->create(['organization_id' => $org->id]);
        $vue = SavedFilter::create([
            'organization_id' => $org->id, 'user_id' => $autre->id, 'scope' => 'mouvements-fonds',
            'name' => 'Externe', 'visibility' => 'shared', 'filters' => ['statut' => 'envoye'],
        ]);
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index', ['saved_view' => $vue->id]))->assertNotFound();
    }

    public function test_une_vue_partagee_ne_peut_pas_elargir_le_perimetre_des_agences(): void
    {
        $externe = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->mouvement('MVT-HORS-PERIMETRE', 250_000, 'envoye', ['site_origine_id' => $externe->id]);
        $id = $this->storeView(['statut' => 'envoye'], ['visibility' => 'shared'])->assertCreated()->json('id');
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $lecteur = User::factory()->create(['organization_id' => $this->org->id]);
        $lecteur->assignRole('manager');
        $lecteur->givePermissionTo('tresorerie.read');
        $lecteur->sites()->attach($this->origine->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($lecteur)->get(route('comptabilite.tresorerie.mouvements.index', ['saved_view' => $id]))
            ->assertInertia(fn (Assert $page) => $page->has('mouvements.data', 1)->where('mouvements.data.0.reference', 'MVT-CIBLE'));
    }
}
