<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SupportTresorerieSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private const SCOPE = 'tresorerie-supports';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read']);
        $this->site = $this->user->sites()->firstOrFail();
        $this->actingAs($this->user);
    }

    private function support(string $libelle, array $attributes = []): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse', 'libelle' => $libelle, 'actif' => true,
            ...$attributes,
        ]);
    }

    private function save(array $filters, bool $default = false, string $visibility = 'personal'): string
    {
        return $this->postJson(route('saved-filters.store', self::SCOPE), [
            'name' => 'Ma vue', 'visibility' => $visibility, 'filters' => $filters, 'is_default' => $default,
        ])->assertCreated()->json('id');
    }

    private function index(array $query = [])
    {
        return $this->get(route('comptabilite.tresorerie.supports.index', $query));
    }

    public function test_view_applies_all_filters_and_survives_agent_rename(): void
    {
        $agent = $this->creerAgent($this->site);
        $selected = $this->support('Caisse dédiée', ['agent_id' => $agent->id]);
        $this->support('Caisse agence');
        $this->support('Caisse brouillon', ['agent_id' => $agent->id, 'actif' => false]);
        $filters = ['site_ids' => [$this->site->id], 'statut' => 'actif', 'type' => 'caisse', 'nature' => 'dediee', 'agent_id' => $agent->id];
        $id = $this->save($filters);
        $agent->update(['prenom' => 'Agent renommé']);

        $this->index(['saved_view' => $id, 'statut' => 'brouillon'])->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('saved_view.id', $id)->where('filters', $filters)->has('comptes', 1)
            ->where('comptes.0.id', $selected->id)->where('comptes.0.agent.id', $agent->id));
    }

    public function test_default_view_applies_on_entry_but_not_manual_filters_or_reset(): void
    {
        $this->support('Active');
        $this->support('Brouillon', ['actif' => false]);
        $id = $this->save(['statut' => 'brouillon'], true);
        $this->index()->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('saved_view.id', $id)->has('comptes', 1)->where('comptes.0.libelle', 'Brouillon'));
        $this->index(['all' => 1])->assertInertia(fn (Assert $p) => $p->where('saved_view', null)->has('comptes', 2));
        $this->index(['statut' => 'actif'])->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('comptes', 1)->where('comptes.0.libelle', 'Active'));
    }

    public static function statusAndNatureFilters(): array
    {
        return [
            'draft' => [['statut' => 'brouillon'], ['actif' => false]],
            'inactive' => [['statut' => 'inactif'], ['actif' => false, 'valide_le' => '2026-01-01']],
            'agency' => [['nature' => 'agence'], []],
            'bank' => [['type' => 'banque'], ['type' => 'banque']],
        ];
    }

    #[DataProvider('statusAndNatureFilters')]
    public function test_views_preserve_status_type_and_nature_semantics(array $filters, array $attributes): void
    {
        $selected = $this->support('Sélection', $attributes);
        $this->support('Autre support', [
            'type' => 'caisse', 'agent_id' => $this->user->id,
            'actif' => true,
        ]);
        $id = $this->save($filters);
        $this->index(['saved_view' => $id])->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('comptes', 1)->where('comptes.0.id', $selected->id));
    }

    public function test_read_or_management_permission_is_required_and_sharing_requires_management(): void
    {
        $payload = ['name' => 'Partagée', 'visibility' => 'shared', 'filters' => ['statut' => 'actif']];
        $this->postJson(route('saved-filters.store', self::SCOPE), $payload)->assertForbidden();
        $this->getJson(route('saved-filters.index', self::SCOPE))->assertOk()->assertJsonPath('can_share', false);
        $this->user->revokePermissionTo('tresorerie.read');
        $this->getJson(route('saved-filters.index', self::SCOPE))->assertForbidden();
        $this->postJson(route('saved-filters.store', self::SCOPE), [...$payload, 'visibility' => 'personal'])->assertForbidden();
        $this->index()->assertForbidden();

        Permission::firstOrCreate(['name' => 'tresorerie.gerer_soldes_ouverture', 'guard_name' => 'web']);
        $this->user->givePermissionTo('tresorerie.gerer_soldes_ouverture');
        $id = $this->postJson(route('saved-filters.store', self::SCOPE), $payload)->assertCreated()->json('id');
        $this->index(['saved_view' => $id])->assertOk();
    }

    public function test_dynamic_shared_view_uses_reader_sites_and_reader_cannot_edit_it(): void
    {
        Permission::firstOrCreate(['name' => 'tresorerie.gerer_soldes_ouverture', 'guard_name' => 'web']);
        $this->user->givePermissionTo('tresorerie.gerer_soldes_ouverture');
        $otherSite = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->support('Auteur');
        $selected = $this->support('Lecteur', ['site_id' => $otherSite->id]);
        $id = $this->save(['site_scope' => 'mine'], true, 'shared');
        $reader = $this->creerUtilisateurNonAdmin($otherSite, ['tresorerie.read']);
        $this->actingAs($reader)->getJson(route('saved-filters.index', self::SCOPE))
            ->assertOk()->assertJsonPath('views.0.can_manage', false)->assertJsonPath('default_id', null);
        $this->index(['saved_view' => $id])->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('filters.site_ids', [$otherSite->id])->has('comptes', 1)->where('comptes.0.id', $selected->id));
        $this->patchJson(route('saved-filters.update', [self::SCOPE, $id]), ['name' => 'Renommée', 'visibility' => 'personal'])->assertNotFound();
        $this->deleteJson(route('saved-filters.destroy', [self::SCOPE, $id]))->assertNotFound();
    }

    public function test_saved_views_never_expand_reader_site_access(): void
    {
        $otherSite = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->support('Accessible');
        $this->support('Hors portée', ['site_id' => $otherSite->id]);
        $reader = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);
        $this->actingAs($reader);
        $this->postJson(route('saved-filters.store', self::SCOPE), [
            'name' => 'Interdite', 'visibility' => 'personal', 'filters' => ['site_ids' => [$otherSite->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('filters.site_ids.0');
        $id = $this->save(['statut' => 'actif']);
        $this->index(['saved_view' => $id])->assertInertia(fn (Assert $p) => $p->has('comptes', 1)->where('comptes.0.libelle', 'Accessible'));
        SavedFilter::findOrFail($id)->update(['filters' => ['site_ids' => [$otherSite->id]]]);
        $this->index(['saved_view' => $id])->assertForbidden();
    }

    public function test_scope_personal_visibility_and_organization_isolation(): void
    {
        $id = $this->save(['statut' => 'actif']);
        $reader = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);
        $this->actingAs($reader)->getJson(route('saved-filters.index', self::SCOPE))->assertJsonCount(0, 'views');
        $this->index(['saved_view' => $id])->assertNotFound();
        $this->actingAs($this->user);
        SavedFilter::findOrFail($id)->update(['scope' => 'stock']);
        $this->index(['saved_view' => $id])->assertNotFound();
        SavedFilter::findOrFail($id)->update(['scope' => self::SCOPE, 'visibility' => 'shared', 'organization_id' => Organization::factory()->create()->id]);
        $this->index(['saved_view' => $id])->assertNotFound();
    }

    public function test_criteria_are_validated_and_foreign_identifiers_rejected(): void
    {
        $foreignAgent = User::factory()->create();
        $foreignSite = Site::factory()->create();
        foreach ([
            ['statut' => 'inconnu'], ['type' => 'inconnu'], ['nature' => 'inconnue'],
            ['agent_id' => $foreignAgent->id], ['site_ids' => [$foreignSite->id]],
            ['stock_statut' => 'rupture'], ['nature' => 'commun'],
        ] as $filters) {
            $this->postJson(route('saved-filters.store', self::SCOPE), [
                'name' => 'Invalide', 'visibility' => 'personal', 'filters' => $filters,
            ])->assertUnprocessable();
        }
        $this->getJson(route('comptabilite.tresorerie.supports.index', ['saved_view' => 'invalide']))
            ->assertUnprocessable()->assertJsonValidationErrors('saved_view');
    }
}
