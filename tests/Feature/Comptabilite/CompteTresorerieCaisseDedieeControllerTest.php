<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Écran Trésorerie > Supports : création et gestion des caisses dédiées à un agent,
 * solde affiché par support (distinct pour la caisse de l'agence et celles des
 * agents d'un même site), filtres serveur. Complète CompteTresorerieControllerTest,
 * qui verrouille le chemin historique des supports d'agence.
 */
class CompteTresorerieCaisseDedieeControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteComptable $compteCaisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);
        $this->site = $this->user->sites()->first();
        $this->compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
    }

    private function caisseAgence(?Site $site = null, string $libelle = 'Caisse agence'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => ($site ?? $this->site)->id,
            'compte_comptable_id' => $this->compteCaisse->id,
            'type' => 'caisse',
            'libelle' => $libelle,
        ]);
    }

    private function caisseDediee(User $agent, ?Site $site = null): CompteTresorerie
    {
        return $this->creerCaisseActive(($site ?? $this->site)->id, $agent->id);
    }

    private function urlIndex(): string
    {
        return route('comptabilite.tresorerie.supports.index');
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_store_cree_une_caisse_dediee_avec_son_sous_compte(): void
    {
        $agent = $this->creerAgent($this->site);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
                'libelle' => '',
            ])
            ->assertRedirect($this->urlIndex())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $caisse = CompteTresorerie::dediees()->firstOrFail();
        $this->assertSame($agent->id, $caisse->agent_id);
        $this->assertSame('caisse', $caisse->type->value);
        $this->assertSame("Caisse {$agent->name}", $caisse->libelle);
        $this->assertSame('571001', $caisse->compte->numero);
    }

    public function test_store_dediee_ignore_un_type_et_un_compte_forges(): void
    {
        $agent = $this->creerAgent($this->site);
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
                'type' => 'banque',
                'compte_comptable_id' => $compteBanque->id,
            ])
            ->assertSessionHasNoErrors();

        $caisse = CompteTresorerie::dediees()->firstOrFail();
        $this->assertSame('caisse', $caisse->type->value);
        $this->assertNotSame($compteBanque->id, $caisse->compte_comptable_id);
        $this->assertSame('571001', $caisse->compte->numero);
    }

    public function test_store_dediee_refuse_un_agent_non_rattache_au_site(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $agent = $this->creerAgent($autreSite);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
            ])
            ->assertSessionHasErrors('agent_id');

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_store_dediee_refuse_une_seconde_caisse_active_pour_le_meme_agent_et_site(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->caisseDediee($agent);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
            ])
            ->assertSessionHasErrors('agent_id');

        $this->assertSame(1, CompteTresorerie::dediees()->count());
    }

    public function test_store_dediee_refuse_un_agent_ou_un_site_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'X', 'type' => 'agence', 'localisation' => 'Y']);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);
        $intrus->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $intrus->id,
            ])
            ->assertSessionHasErrors('agent_id');

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $autreSite->id,
                'agent_id' => $this->creerAgent($this->site)->id,
            ])
            ->assertSessionHasErrors('site_id');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_store_dediee_refuse_sans_permission(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->user->syncPermissions([]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_store_refuse_une_nature_inconnue(): void
    {
        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'autre',
                'site_id' => $this->site->id,
            ])
            ->assertSessionHasErrors('nature');
    }

    public function test_store_sans_nature_reste_un_support_d_agence(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $this->site->id,
                'compte_comptable_id' => $this->compteCaisse->id,
                'type' => 'caisse',
                'libelle' => 'Caisse Matoto',
            ])
            ->assertSessionHasNoErrors();

        $support = CompteTresorerie::firstOrFail();
        $this->assertFalse($support->isDediee());
        $this->assertNull($support->agent_id);
    }

    // ── index : soldes, nature, filtres ──────────────────────────────────────

    public function test_index_affiche_un_solde_distinct_pour_la_caisse_de_l_agence_et_celle_de_l_agent(): void
    {
        $agence = $this->caisseAgence();
        app(SoldeOuvertureTresorerieService::class)->valider(
            app(SoldeOuvertureTresorerieService::class)->enregistrer($this->org->id, $agence, ['date_situation' => '2026-08-01', 'montant' => 2_000_000], $this->user->id),
            $this->user->id,
        );
        $agent = $this->creerAgent($this->site);
        $dediee = $this->caisseDediee($agent);
        $this->alimenterCaisse($dediee, 500_000);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(function (Assert $page) use ($agence, $dediee, $agent) {
                $comptes = collect($page->toArray()['props']['comptes'])->keyBy('id');

                $this->assertCount(2, $comptes);
                $this->assertSame('agence', $comptes[$agence->id]['nature']);
                $this->assertNull($comptes[$agence->id]['agent']);
                $this->assertEquals(2_000_000, $comptes[$agence->id]['solde']);
                $this->assertSame('dediee', $comptes[$dediee->id]['nature']);
                $this->assertSame(['id' => $agent->id, 'nom' => $agent->name], $comptes[$dediee->id]['agent']);
                $this->assertEquals(500_000, $comptes[$dediee->id]['solde']);
                $this->assertSame('571001', $comptes[$dediee->id]['compte_numero']);
            });
    }

    public function test_index_liste_la_caisse_de_l_agence_avant_les_caisses_dediees_du_meme_site(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));
        $agence = $this->caisseAgence(libelle: 'Zèbre caisse');

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('comptes.0.id', $agence->id)
                ->where('comptes.1.id', $dediee->id));
    }

    public function test_index_affiche_le_solde_d_un_support_desactive(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));
        $this->alimenterCaisse($dediee, 300_000);
        $dediee->update(['actif' => false]);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('comptes.0.actif', false)
                ->where('comptes.0.solde', fn ($solde) => (float) $solde === 300000.0));
    }

    public function test_index_filtre_par_nature_type_agent_statut_et_agence(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $agence = $this->caisseAgence();
        $banque = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail()->id,
            'type' => 'banque',
            'libelle' => 'UBA',
        ]);
        $agentA = $this->creerAgent($this->site, 'Aïssata', 'Barry');
        $agentB = $this->creerAgent($autreSite, 'Bakary', 'Camara');
        $dedieeA = $this->caisseDediee($agentA);
        $dedieeB = $this->caisseDediee($agentB, $autreSite);
        $dedieeB->update(['actif' => false]);

        $ids = fn (array $query) => collect($this->actingAs($this->user)
            ->get($this->urlIndex().'?'.http_build_query($query))
            ->viewData('page')['props']['comptes'])->pluck('id')->sort()->values()->all();

        $tous = collect([$agence, $banque, $dedieeA, $dedieeB])->pluck('id')->sort()->values()->all();
        $this->assertSame($tous, $ids([]));
        $this->assertSame(collect([$dedieeA, $dedieeB])->pluck('id')->sort()->values()->all(), $ids(['nature' => 'dediee']));
        $this->assertSame(collect([$agence, $banque])->pluck('id')->sort()->values()->all(), $ids(['nature' => 'agence']));
        $this->assertSame([$banque->id], $ids(['type' => 'banque']));
        $this->assertSame([$dedieeA->id], $ids(['agent_id' => $agentA->id]));
        $this->assertSame([$dedieeB->id], $ids(['statut' => 'inactif']));
        $this->assertSame(collect([$agence, $banque, $dedieeA])->pluck('id')->sort()->values()->all(), $ids(['statut' => 'actif']));
        $this->assertSame([$dedieeB->id], $ids(['site_ids' => [$autreSite->id]]));
        $this->assertSame($tous, $ids(['type' => 'inconnu', 'nature' => 'inconnue', 'statut' => 'inconnu']), 'les valeurs de filtre inconnues sont ignorées');
    }

    public function test_index_ne_montre_jamais_les_supports_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $autreAgent = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreAgent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);
        app(CaisseAgentService::class)->creer($autreOrg->id, $autreSite->id, $autreAgent->id);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->has('comptes', 0)
                ->has('agents_filtre', 0)
                ->has('caisses_dediees_actives', 0)
                ->where('agents', fn ($agents) => collect($agents)->doesntContain('id', $autreAgent->id)));
    }

    public function test_index_propose_uniquement_les_agents_actifs_rattaches_a_un_site(): void
    {
        $actif = $this->creerAgent($this->site, 'Aïssata', 'Barry');
        $inactif = $this->creerAgent($this->site, 'Bakary', 'Camara');
        $inactif->update(['is_active' => false]);
        $sansSite = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(function (Assert $page) use ($actif, $inactif, $sansSite) {
                $agents = collect($page->toArray()['props']['agents']);

                $this->assertTrue($agents->contains('id', $actif->id));
                $this->assertFalse($agents->contains('id', $inactif->id));
                $this->assertFalse($agents->contains('id', $sansSite->id));
                $this->assertSame([$this->site->id], $agents->firstWhere('id', $actif->id)['site_ids']);
            });
    }

    public function test_index_expose_les_caisses_dediees_actives_pour_masquer_les_agents_deja_servis(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->caisseDediee($agent);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->has('caisses_dediees_actives', 1)
                ->where('caisses_dediees_actives.0.agent_id', $agent->id)
                ->where('caisses_dediees_actives.0.site_id', $this->site->id));
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_dediee_modifie_le_libelle_et_ignore_un_type_ou_un_compte_forges(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));
        $compteAvant = $dediee->compte_comptable_id;
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $dediee), [
                'libelle' => 'Caisse comptoir',
                'actif' => true,
                'type' => 'banque',
                'compte_comptable_id' => $compteBanque->id,
                'agent_id' => $this->user->id,
            ])
            ->assertSessionHasNoErrors();

        $dediee->refresh();
        $this->assertSame('Caisse comptoir', $dediee->libelle);
        $this->assertSame('caisse', $dediee->type->value);
        $this->assertSame($compteAvant, $dediee->compte_comptable_id);
        $this->assertNotSame($this->user->id, $dediee->agent_id);
    }

    public function test_update_dediee_refuse_la_desactivation_tant_que_la_caisse_detient_de_l_argent(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));
        $piece = $this->alimenterCaisse($dediee, 400_000);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->put(route('comptabilite.tresorerie.supports.update', $dediee), ['libelle' => $dediee->libelle, 'actif' => false])
            ->assertSessionHasErrors('actif');
        $this->assertTrue($dediee->fresh()->actif);

        $this->viderCaisse($piece);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $dediee), ['libelle' => $dediee->libelle, 'actif' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($dediee->fresh()->actif);
    }

    public function test_update_dediee_refuse_sans_permission_et_pour_une_autre_organisation(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));

        $autreOrg = Organization::factory()->create();
        $autreUser = $this->makeUserWithPermissions($autreOrg, ['tresorerie.gerer_soldes_ouverture']);
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $autreUser->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($autreUser)
            ->put(route('comptabilite.tresorerie.supports.update', $dediee), ['libelle' => 'Piratage', 'actif' => true])
            ->assertStatus(403);

        $this->user->syncPermissions([]);
        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $dediee), ['libelle' => 'Piratage', 'actif' => true])
            ->assertStatus(403);

        $this->assertNotSame('Piratage', $dediee->fresh()->libelle);
    }

    // ── solde d'ouverture ────────────────────────────────────────────────────

    public function test_un_solde_d_ouverture_est_refuse_pour_une_caisse_dediee(): void
    {
        $dediee = $this->caisseDediee($this->creerAgent($this->site));

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.soldes-ouverture.store'), [
                'compte_tresorerie_id' => $dediee->id,
                'date_situation' => '2026-09-01',
                'montant' => 100_000,
            ])
            ->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseCount('compta_soldes_ouverture', 0);
    }
}
