<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Effets d'une caisse dédiée à un agent sur le reste de la trésorerie (décision du
 * 2026-09-19) :
 *  - Situation = où est l'argent → elle INCLUT les caisses dédiées, avec un solde
 *    distinct de la caisse de l'agence du même site ;
 *  - le « disponible » (Financement) NE les compte PAS avant versement ;
 *  - un mouvement entre agences ne peut ni partir d'une caisse dédiée ni y arriver ;
 *  - la suppression d'un agent qui détient une caisse active est refusée.
 * Les règles de Financement (position fiable, à financer) sont aussi verrouillées dans
 * FinancementAgenceServiceTest.
 */
class CaisseAgentImpactTresorerieTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private TresorerieDisponibiliteService $disponibilite;

    private Site $site;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseDediee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.create', 'tresorerie.gerer_soldes_ouverture']);
        $this->disponibilite = app(TresorerieDisponibiliteService::class);
        $this->site = $this->user->sites()->first();

        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse agence',
        ]);
        $soldes = app(SoldeOuvertureTresorerieService::class);
        $soldes->valider(
            $soldes->enregistrer($this->org->id, $this->caisseAgence, ['date_situation' => '2026-08-01', 'montant' => 2_000_000], $this->user->id),
            $this->user->id,
        );

        $this->caisseDediee = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site)->id);
        $this->alimenterCaisse($this->caisseDediee, 500_000);
    }

    // ── Disponible vs Situation ──────────────────────────────────────────────

    public function test_le_disponible_d_un_site_ne_compte_pas_les_caisses_dediees(): void
    {
        $this->assertSame(2_000_000.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));
    }

    public function test_la_situation_par_support_inclut_la_caisse_dediee_avec_un_solde_distinct(): void
    {
        $situation = $this->disponibilite->situationParSupport($this->org->id, Carbon::now())->keyBy('compte_tresorerie_id');

        $this->assertCount(2, $situation);
        $this->assertSame(2_000_000.0, $situation[$this->caisseAgence->id]['solde']);
        $this->assertNull($situation[$this->caisseAgence->id]['agent_id']);
        $this->assertSame(500_000.0, $situation[$this->caisseDediee->id]['solde']);
        $this->assertSame($this->caisseDediee->agent_id, $situation[$this->caisseDediee->id]['agent_id']);
    }

    public function test_une_caisse_desactivee_sort_de_la_situation_sauf_demande_explicite(): void
    {
        $this->viderCaisse($this->alimenterCaisse($this->caisseDediee, 500_000));
        $this->caisseDediee->update(['actif' => false]);

        $this->assertCount(1, $this->disponibilite->situationParSupport($this->org->id, Carbon::now()));
        $this->assertCount(2, $this->disponibilite->situationParSupport($this->org->id, Carbon::now(), true));
    }

    public function test_solde_pour_support_lit_le_grand_livre_du_seul_support_demande(): void
    {
        $this->assertSame(500_000.0, $this->disponibilite->soldePourSupport($this->caisseDediee));
        $this->assertSame(2_000_000.0, $this->disponibilite->soldePourSupport($this->caisseAgence));
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseDediee, Carbon::now()->subDay()), 'avant la date de la pièce');
    }

    public function test_la_page_situation_totalise_l_argent_des_agents_avec_celui_de_l_agence(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.situation.index'))
            ->assertInertia(function (Assert $page) {
                $ligne = collect($page->toArray()['props']['rows'])->firstWhere('site_id', $this->site->id);

                $this->assertEquals(2_500_000, $ligne['par_type']['caisse']);
                $this->assertEquals(2_500_000, $ligne['total']);
                $this->assertEquals(2_500_000, $page->toArray()['props']['total_general']['total']);
            });
    }

    public function test_le_detail_d_une_agence_liste_chaque_caisse_avec_son_solde(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.situation.show', $this->site->id))
            ->assertInertia(function (Assert $page) {
                $supports = collect($page->toArray()['props']['supports'])->keyBy('compte_tresorerie_id');

                $this->assertEquals(2_000_000, $supports[$this->caisseAgence->id]['solde']);
                $this->assertEquals(500_000, $supports[$this->caisseDediee->id]['solde']);
                $this->assertEquals(2_500_000, $page->toArray()['props']['total']);
            });
    }

    // ── Mouvements de fonds entre agences ────────────────────────────────────

    private function siegeAvecCaisse(): array
    {
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $caisse = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $siege->id,
            'compte_comptable_id' => $this->caisseAgence->compte_comptable_id,
            'type' => 'caisse',
            'libelle' => 'Caisse Siège',
        ]);

        return [$siege, $caisse];
    }

    public function test_un_mouvement_ne_peut_pas_partir_d_une_caisse_dediee(): void
    {
        [$siege] = $this->siegeAvecCaisse();

        $this->assertErreurValidationSur('compte_tresorerie_origine_id', fn () => app(MouvementFondsService::class)->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $siege->id,
            'compte_tresorerie_origine_id' => $this->caisseDediee->id,
            'montant' => 100_000,
        ], $this->user->id));

        $this->assertDatabaseCount('mouvements_fonds', 0);
    }

    public function test_un_mouvement_ne_peut_pas_cibler_une_caisse_dediee_a_la_creation(): void
    {
        [$siege, $caisseSiege] = $this->siegeAvecCaisse();

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => app(MouvementFondsService::class)->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseDediee->id,
            'montant' => 100_000,
        ], $this->user->id));
    }

    public function test_la_reception_d_un_mouvement_refuse_une_caisse_dediee_comme_destination(): void
    {
        [$siege, $caisseSiege] = $this->siegeAvecCaisse();
        // garantirSoldeSuffisant() (règle du 22/09/2026) exige un solde disponible avant l'envoi.
        $this->alimenterCaisse($caisseSiege, 100_000);
        $service = app(MouvementFondsService::class);
        $mouvement = $service->envoyer($service->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'montant' => 100_000,
        ], $this->user->id), $this->user->id);

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $service->recevoir($mouvement, $this->user->id, $this->caisseDediee->id));

        $this->assertSame('envoye', $mouvement->fresh()->statut->value);
        $this->assertNull($mouvement->fresh()->piece_comptable_reception_id);

        $service->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);
        $this->assertSame('recu', $mouvement->fresh()->statut->value, 'la caisse de l\'agence reste acceptée');
    }

    public function test_les_ecrans_de_mouvements_ne_proposent_pas_les_caisses_dediees(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.create'))
            ->assertInertia(function (Assert $page) {
                $ids = collect($page->toArray()['props']['comptes_tresorerie'])->pluck('id');

                $this->assertTrue($ids->contains($this->caisseAgence->id));
                $this->assertFalse($ids->contains($this->caisseDediee->id));
            });

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertInertia(function (Assert $page) {
                $ids = collect($page->toArray()['props']['comptes_tresorerie'])->pluck('id');

                $this->assertTrue($ids->contains($this->caisseAgence->id));
                $this->assertFalse($ids->contains($this->caisseDediee->id));
            });
    }

    // ── Suppression d'un agent ───────────────────────────────────────────────

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $superAdmin;
    }

    public function test_la_suppression_d_un_agent_qui_detient_une_caisse_active_est_refusee(): void
    {
        $agent = $this->caisseDediee->agent;

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $agent))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $agent->id]);
        $this->assertSame($agent->id, $this->caisseDediee->fresh()->agent_id);
    }

    public function test_la_suppression_d_un_agent_dont_la_caisse_est_encore_en_brouillon_est_refusee(): void
    {
        $agent = $this->creerAgent($this->site, 'Bakary', 'Camara');
        $brouillon = app(CaisseAgentService::class)->creer($this->org->id, $this->site->id, $agent->id);

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $agent))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $agent->id]);
        $this->assertSame($agent->id, $brouillon->fresh()->agent_id, 'un brouillon ne devient jamais un support d\'agence');
    }

    public function test_la_suppression_d_un_agent_dont_la_caisse_est_desactivee_est_acceptee(): void
    {
        $this->viderCaisse($this->alimenterCaisse($this->caisseDediee, 500_000));
        app(CaisseAgentService::class)->mettreAJour($this->caisseDediee, ['libelle' => $this->caisseDediee->libelle, 'actif' => false]);
        $agent = $this->caisseDediee->agent;

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $agent))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $agent->id]);
        $this->assertDatabaseHas('compta_supports_tresorerie', ['id' => $this->caisseDediee->id, 'actif' => false]);
    }
}
