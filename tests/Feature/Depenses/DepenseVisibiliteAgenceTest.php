<?php

namespace Tests\Feature\Depenses;

use App\Models\Client;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Depenses\DepensePerimetre;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Périmètre de consultation des dépenses (ADR 0065, docs/depenses-visibilite.md) : ses agences de
 * rattachement + celles où son rôle valide + ses propres saisies ; toutes les agences avec la vision
 * de toutes les agences (administrateur, superviseur, vision 360°) ou la permission du rôle
 * `depenses.lecture_toutes_agences`. Les `site_ids[]` de l'URL ne font que restreindre.
 */
class DepenseVisibiliteAgenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    private Site $siteC;

    private DepenseType $type;

    private Depense $depenseA;

    private Depense $depenseB;

    private Depense $depenseC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->siteA = $this->creerSite('Agence A');
        $this->siteB = $this->creerSite('Agence B');
        $this->siteC = $this->creerSite('Agence C');
        $this->type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);

        foreach ([...PermissionCatalog::crudPermissionNames(), ...array_keys(PermissionCatalog::STANDALONE)] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->depenseA = $this->depense($auteur, $this->siteA, 'DEP-AGENCE-A');
        $this->depenseB = $this->depense($auteur, $this->siteB, 'DEP-AGENCE-B');
        $this->depenseC = $this->depense($auteur, $this->siteC, 'DEP-AGENCE-C');
    }

    // ── Liste et statistiques ─────────────────────────────────────────────────

    public function test_manager_ne_voit_que_les_depenses_de_son_agence(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 1)
                ->where('depenses.data.0.id', $this->depenseA->id)
                ->where('stats.total', 1)
                ->where('voit_toutes_agences', false)
                ->where('sites', [['id' => $this->siteA->id, 'nom' => 'Agence A']])
            );
    }

    public function test_manager_ne_peut_pas_elargir_son_perimetre_par_l_url(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);

        $this->actingAs($manager)
            ->get(route('depenses.index', ['site_ids' => [$this->siteB->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 0)
                ->where('stats.total', 0)
                ->where('filters.site_ids', [])
            );

        $this->actingAs($manager)
            ->get(route('depenses.index', ['site_ids' => [$this->siteA->id, $this->siteB->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 1)
                ->where('depenses.data.0.id', $this->depenseA->id)
                ->where('filters.site_ids', [$this->siteA->id])
            );
    }

    public function test_utilisateur_rattache_a_plusieurs_agences_voit_et_filtre_ses_agences(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA, $this->siteB]);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 2)
                ->has('sites', 2)
                ->where('voit_toutes_agences', false)
            );

        $this->actingAs($manager)
            ->get(route('depenses.index', ['site_ids' => [$this->siteB->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 1)
                ->where('depenses.data.0.id', $this->depenseB->id)
            );
    }

    public function test_superviseur_voit_toutes_les_agences(): void
    {
        $this->assertVoitTout($this->utilisateur('superviseur', [$this->siteA]));
    }

    public function test_administrateur_entreprise_voit_toutes_les_agences(): void
    {
        $this->assertVoitTout($this->utilisateur('admin_entreprise', [$this->siteA]));
    }

    public function test_vision_360_voit_toutes_les_agences(): void
    {
        $this->assertVoitTout($this->utilisateur('assistante_de_direction', [$this->siteA], [User::PERMISSION_LECTURE_TOUTES_AGENCES]));
    }

    public function test_permission_du_role_ouvre_toutes_les_agences_et_le_filtre_multi_agences(): void
    {
        $comptable = $this->utilisateur('comptable', [$this->siteA], [DepensePerimetre::PERMISSION_TOUTES_AGENCES]);

        $this->assertVoitTout($comptable);

        $this->actingAs($comptable)
            ->get(route('depenses.index', ['site_ids' => [$this->siteB->id, $this->siteC->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 2)
                ->where('filters.site_ids', [$this->siteB->id, $this->siteC->id])
            );
    }

    public function test_cocher_ou_decocher_la_permission_sur_le_role_change_le_perimetre(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);
        $role = Role::findByName('manager', 'web');

        $role->givePermissionTo(DepensePerimetre::PERMISSION_TOUTES_AGENCES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager->fresh())->get(route('depenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('depenses.total', 3));

        $role->revokePermissionTo(DepensePerimetre::PERMISSION_TOUTES_AGENCES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager->fresh())->get(route('depenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('depenses.total', 1));
    }

    public function test_agences_ou_le_role_valide_sont_consultables(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA], ['depenses.update']);
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'is_actif' => true,
            'peut_valider' => true,
            'perimetre' => 'agences_selectionnees',
            'sites' => [$this->siteB->id],
            'plafond_validation' => 1000000,
        ]);

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 2)
                ->has('sites', 2)
            );
    }

    public function test_ses_propres_saisies_restent_visibles_hors_perimetre(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);
        $saisie = $this->depense($manager, $this->siteC, 'MA-SAISIE');

        $this->actingAs($manager)
            ->get(route('depenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('depenses.total', 2));

        $this->actingAs($manager)->get(route('depenses.show', $saisie))->assertOk();
    }

    public function test_depense_sans_agence_reservee_a_la_vision_toutes_agences(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $sansAgence = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $auteur->id,
            'depense_type_id' => $this->type->id,
            'site_id' => null,
        ]);

        $manager = $this->utilisateur('manager', [$this->siteA]);
        $this->actingAs($manager)->get(route('depenses.show', $sansAgence))->assertForbidden();

        $admin = $this->utilisateur('admin_entreprise', [$this->siteA]);
        $this->actingAs($admin)->get(route('depenses.show', $sansAgence))->assertOk();
    }

    // ── Export et impression ──────────────────────────────────────────────────

    public function test_export_et_impression_respectent_le_perimetre(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);

        $csv = $this->actingAs($manager)
            ->get(route('depenses.export.excel', ['site_ids' => [$this->siteA->id, $this->siteB->id]]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('DEP-AGENCE-A', $csv);
        $this->assertStringNotContainsString('DEP-AGENCE-B', $csv);
        $this->assertStringNotContainsString('DEP-AGENCE-C', $csv);

        $this->actingAs($manager)
            ->get(route('depenses.imprimer'))
            ->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->flatMap(fn ($s) => collect($s['rows'])->pluck('id'))->all() === [$this->depenseA->id]);
    }

    // ── Fiche, historique, actions, suggestions ───────────────────────────────

    public function test_fiche_historique_et_actions_hors_perimetre_refusees(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA], ['depenses.update', 'depenses.delete']);

        $this->actingAs($manager)->get(route('depenses.show', $this->depenseB))->assertForbidden();
        $this->actingAs($manager)->getJson(route('depenses.historique', $this->depenseB))->assertForbidden();
        $this->actingAs($manager)->get(route('depenses.edit', $this->depenseB))->assertForbidden();
        $this->actingAs($manager)->patch(route('depenses.soumettre', $this->depenseB))->assertForbidden();
        $this->actingAs($manager)->delete(route('depenses.destroy', $this->depenseB))->assertForbidden();

        $this->actingAs($manager)->get(route('depenses.show', $this->depenseA))->assertOk();
        $this->actingAs($manager)->getJson(route('depenses.historique', $this->depenseA))->assertOk();
    }

    public function test_suggestions_limitees_au_perimetre(): void
    {
        $manager = $this->utilisateur('manager', [$this->siteA]);
        $clientB = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Zabulon', 'prenom' => 'Hors']);
        $this->depenseB->update(['beneficiaire_type' => 'client', 'beneficiaire_id' => $clientB->id]);

        $this->actingAs($manager)
            ->getJson(route('depenses.suggestions', ['field' => 'concerne', 'q' => 'Zabulon']))
            ->assertOk()
            ->assertExactJson([]);

        $admin = $this->utilisateur('admin_entreprise', [$this->siteA]);
        $this->actingAs($admin)
            ->getJson(route('depenses.suggestions', ['field' => 'concerne', 'q' => 'Zabulon']))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_isolation_entre_organisations(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'X']);
        $etrangere = Depense::factory()->brouillon()->create([
            'organization_id' => $autreOrg->id,
            'user_id' => User::factory()->create(['organization_id' => $autreOrg->id])->id,
            'depense_type_id' => DepenseType::factory()->interne()->create(['organization_id' => $autreOrg->id])->id,
            'site_id' => $autreSite->id,
        ]);

        $comptable = $this->utilisateur('comptable', [$this->siteA], [DepensePerimetre::PERMISSION_TOUTES_AGENCES]);

        $this->actingAs($comptable)->get(route('depenses.show', $etrangere))->assertForbidden();
        $this->actingAs($comptable)->get(route('depenses.index', ['site_ids' => [$autreSite->id]]))
            ->assertInertia(fn (Assert $page) => $page->where('depenses.total', 0));
    }

    // ── Agence obligatoire ────────────────────────────────────────────────────

    public function test_creation_sans_agence_refusee(): void
    {
        $admin = $this->utilisateur('admin_entreprise', [$this->siteA], ['depenses.create']);
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'is_actif' => true,
            'perimetre' => 'toutes_agences',
        ]);

        $this->actingAs($admin)
            ->post(route('depenses.store'), [
                'depense_type_id' => $this->type->id,
                'montant' => 5000,
                'date_depense' => now()->toDateString(),
                'statut' => 'brouillon',
            ])
            ->assertSessionHasErrors(['site_id' => "L'agence est obligatoire."]);

        $this->assertDatabaseMissing('depenses', ['montant' => 5000]);
    }

    public function test_permission_rangee_dans_le_domaine_depenses(): void
    {
        $this->assertSame(
            ['depenses.lecture_toutes_agences'],
            PermissionCatalog::DOMAINS['depenses']['standalone']['Périmètre de visibilité'],
        );
    }

    // ── Outils ────────────────────────────────────────────────────────────────

    private function assertVoitTout(User $user): void
    {
        $this->actingAs($user)
            ->get(route('depenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('depenses.total', 3)
                ->where('stats.total', 3)
                ->where('voit_toutes_agences', true)
                ->has('sites', 3)
            );

        $this->actingAs($user)->get(route('depenses.show', $this->depenseC))->assertOk();
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissionsEnPlus
     */
    private function utilisateur(string $role, array $sites, array $permissionsEnPlus = []): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $roleModel->givePermissionTo(['depenses.read', ...$permissionsEnPlus]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($roleModel);
        foreach ($sites as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function depense(User $auteur, Site $site, string $commentaire): Depense
    {
        return Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $auteur->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $site->id,
            'commentaire' => $commentaire,
        ]);
    }

    private function creerSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }
}
