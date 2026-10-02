<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\NatureMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Affichage du montant « en cours de versement » : un versement Envoyé a déjà quitté la caisse de
 * l'agent (transit au grand livre) mais n'est pas encore crédité à la caisse de l'agence. Les
 * écrans Supports et Situation l'expliquent à part, SANS jamais l'ajouter à un solde :
 * Solde ≠ en cours de versement ≠ reçu. Le grand livre, les écritures et le workflow Envoyé → Reçu
 * ne sont pas concernés — seule la lecture des mouvements est ajoutée.
 */
class VersementEnCoursAffichageTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private MouvementFondsService $service;

    private TresorerieDisponibiliteService $disponibilite;

    private Site $site;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseAgent;

    private User $receveur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.verser']);
        $this->service = app(MouvementFondsService::class);
        $this->disponibilite = app(TresorerieDisponibiliteService::class);
        $this->site = $this->user->sites()->first();

        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse principale',
        ]);
        $this->caisseAgent = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site)->id);
        $this->alimenterCaisse($this->caisseAgent, 850_000);

        $this->receveur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.recevoir', 'tresorerie.rejeter'], 'Ibrahima', 'Caissier');
    }

    private function verser(float $montant): MouvementFonds
    {
        return $this->service->verserCaisseAgent($this->org->id, $this->caisseAgent, $this->caisseAgence->id, $montant, null, $this->user->id);
    }

    private function recevoir(MouvementFonds $mouvement, ?Carbon $date = null): MouvementFonds
    {
        return $this->service->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id, $date);
    }

    /** @return array<string, array<string, mixed>> lignes de l'écran Supports, indexées par id de support */
    private function lignesSupports(): array
    {
        $lignes = [];
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertInertia(function (Assert $page) use (&$lignes) {
                $lignes = collect($page->toArray()['props']['comptes'])->keyBy('id')->all();
            });

        return $lignes;
    }

    /** @return array<string, mixed> props de la Situation (liste des agences) */
    private function propsSituation(?User $utilisateur = null): array
    {
        $props = [];
        $this->actingAs($utilisateur ?? $this->user)
            ->get(route('comptabilite.tresorerie.situation.index'))
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ── Écran Supports ───────────────────────────────────────────────────────

    public function test_sans_versement_en_cours_rien_n_est_a_signaler(): void
    {
        $lignes = $this->lignesSupports();

        $this->assertEquals(850_000, $lignes[$this->caisseAgent->id]['solde']);
        $this->assertEquals(0, $lignes[$this->caisseAgent->id]['en_cours_versement']);
        $this->assertSame(0, $lignes[$this->caisseAgent->id]['versements_en_cours']);
        $this->assertEquals(0, $lignes[$this->caisseAgence->id]['en_cours_versement']);
    }

    public function test_un_versement_envoye_est_signale_sans_etre_ajoute_a_aucun_solde(): void
    {
        $this->verser(800_000);

        $lignes = $this->lignesSupports();
        $agent = $lignes[$this->caisseAgent->id];
        $agence = $lignes[$this->caisseAgence->id];

        $this->assertEquals(50_000, $agent['solde'], 'le solde de la caisse de l\'agent a baissé de 800 000');
        $this->assertEquals(800_000, $agent['en_cours_versement']);
        $this->assertSame(1, $agent['versements_en_cours']);
        $this->assertEquals(850_000, $agent['solde'] + $agent['en_cours_versement'], 'solde + en cours = ce que la caisse détenait avant le versement');

        $this->assertEquals(0, $agence['solde'], 'la caisse de l\'agence n\'est pas créditée avant la réception');
        $this->assertEquals(0, $agence['en_cours_versement'], 'l\'en cours se lit sur la caisse qui verse, pas sur celle qui reçoit');
    }

    public function test_apres_reception_l_argent_est_dans_la_caisse_de_l_agence_et_plus_en_cours(): void
    {
        $this->recevoir($this->verser(800_000));

        $lignes = $this->lignesSupports();

        $this->assertEquals(50_000, $lignes[$this->caisseAgent->id]['solde']);
        $this->assertEquals(0, $lignes[$this->caisseAgent->id]['en_cours_versement']);
        $this->assertSame(0, $lignes[$this->caisseAgent->id]['versements_en_cours']);
        $this->assertEquals(800_000, $lignes[$this->caisseAgence->id]['solde']);
    }

    public function test_plusieurs_versements_envoyes_sont_additionnes_et_comptes(): void
    {
        $this->verser(300_000);
        $this->verser(200_000);

        $agent = $this->lignesSupports()[$this->caisseAgent->id];

        $this->assertEquals(500_000, $agent['en_cours_versement']);
        $this->assertSame(2, $agent['versements_en_cours']);
        $this->assertEquals(350_000, $agent['solde']);
    }

    public function test_un_versement_conteste_reste_en_cours_jusqu_a_son_retour(): void
    {
        $conteste = $this->service->contester($this->verser(800_000), $this->receveur->id, 'Rien reçu');

        $agent = $this->lignesSupports()[$this->caisseAgent->id];
        $this->assertEquals(800_000, $agent['en_cours_versement'], 'un litige non résolu laisse l\'argent en transit');
        $this->assertEquals(50_000, $agent['solde']);

        $this->service->confirmerRetour($conteste, $this->user->id, 'Les fonds sont revenus');

        $agent = $this->lignesSupports()[$this->caisseAgent->id];
        $this->assertEquals(0, $agent['en_cours_versement']);
        $this->assertEquals(850_000, $agent['solde'], 'le retour recrédite la caisse de l\'agent');
    }

    // ── Situation de trésorerie ──────────────────────────────────────────────

    public function test_la_situation_explique_le_total_qui_baisse_sans_en_modifier_le_calcul(): void
    {
        $avant = $this->propsSituation();
        $this->assertEquals(850_000, $avant['total_general']['total']);
        $this->assertEquals(0, $avant['total_general']['en_cours_versement']);

        $this->verser(800_000);
        $pendant = $this->propsSituation();

        $this->assertEquals(50_000, $pendant['total_general']['total'], 'le calcul de la Situation n\'a pas changé : l\'argent en transit n\'y est pas');
        $this->assertEquals(800_000, $pendant['total_general']['en_cours_versement']);
        $this->assertSame(1, $pendant['total_general']['versements_en_cours']);
        $this->assertEquals(800_000, $pendant['rows'][0]['en_cours_versement']);
        $this->assertEquals(50_000, $pendant['rows'][0]['total']);
    }

    public function test_la_situation_revient_a_sa_valeur_initiale_apres_la_reception(): void
    {
        $this->recevoir($this->verser(800_000));

        $apres = $this->propsSituation();

        $this->assertEquals(850_000, $apres['total_general']['total']);
        $this->assertEquals(0, $apres['total_general']['en_cours_versement']);
        $this->assertSame(0, $apres['total_general']['versements_en_cours']);
    }

    public function test_la_fiche_situation_d_une_agence_detaille_l_en_cours_par_caisse(): void
    {
        $this->verser(800_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.situation.show', $this->site->id))
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $supports = collect($props['supports'])->keyBy('compte_tresorerie_id');

                $this->assertEquals(50_000, $props['total']);
                $this->assertEquals(800_000, $props['en_cours_versement']);
                $this->assertSame(1, $props['versements_en_cours']);
                $this->assertEquals(800_000, $supports[$this->caisseAgent->id]['en_cours_versement']);
                $this->assertEquals(50_000, $supports[$this->caisseAgent->id]['solde']);
                $this->assertEquals(0, $supports[$this->caisseAgence->id]['en_cours_versement']);
            });
    }

    public function test_un_utilisateur_ne_voit_l_en_cours_que_de_ses_agences(): void
    {
        $this->verser(800_000);
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $ailleurs = $this->creerUtilisateurNonAdmin($autreSite, ['tresorerie.read'], 'Mariama', 'Kouria');

        $props = $this->propsSituation($ailleurs);

        $this->assertEquals(0, $props['total_general']['en_cours_versement'], 'le versement d\'une autre agence n\'est pas visible');
        $this->assertCount(1, $props['rows']);
        $this->assertSame($autreSite->id, $props['rows'][0]['site_id']);
    }

    // ── TresorerieDisponibiliteService::versementsEnCours() ──────────────────

    public function test_le_service_ne_compte_que_les_versements_internes_non_recus(): void
    {
        $this->verser(100_000);
        $recu = $this->recevoir($this->verser(200_000));
        $this->assertSame(NatureMouvementFonds::INTERNE_CAISSES, $recu->nature);

        // Un mouvement entre agences envoyé, non reçu : il n'a rien à voir avec un versement de caisse.
        // Alimentation nécessaire depuis la règle du 22/09/2026 (garantirSoldeSuffisant() s'applique
        // désormais aussi aux mouvements entre agences) : seuls 200 000 ont réellement été crédités
        // à caisseAgence via recevoir() ci-dessus, le versement de 100 000 restant en transit.
        $this->alimenterCaisse($this->caisseAgence, 999_000);
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $entreAgences = $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $autreSite->id,
            'compte_tresorerie_origine_id' => $this->caisseAgence->id,
            'montant' => 999_000,
        ], $this->user->id);
        $this->service->envoyer($entreAgences, $this->user->id);

        $lignes = $this->disponibilite->versementsEnCours($this->org->id);

        $this->assertCount(1, $lignes);
        $this->assertSame($this->caisseAgent->id, $lignes[0]['compte_tresorerie_id']);
        $this->assertSame($this->site->id, $lignes[0]['site_id']);
        $this->assertSame(100_000.0, $lignes[0]['montant'], 'ni le versement reçu, ni le mouvement entre agences');
        $this->assertSame(1, $lignes[0]['nombre']);
    }

    public function test_le_service_ignore_une_autre_organisation_et_respecte_la_liste_de_caisses(): void
    {
        $this->verser(100_000);
        $autreOrg = Organization::factory()->create();

        $this->assertCount(0, $this->disponibilite->versementsEnCours($autreOrg->id));
        $this->assertCount(0, $this->disponibilite->versementsEnCours($this->org->id, null, [$this->caisseAgence->id]));
        $this->assertCount(1, $this->disponibilite->versementsEnCours($this->org->id, null, [$this->caisseAgent->id]));
        $this->assertCount(0, $this->disponibilite->versementsEnCours($this->org->id, null, []));
    }

    public function test_le_service_tient_compte_de_la_date_de_situation(): void
    {
        $mouvement = $this->verser(100_000);

        $this->assertCount(0, $this->disponibilite->versementsEnCours($this->org->id, now()->subDay()), 'pas encore envoyé la veille');
        $this->assertCount(1, $this->disponibilite->versementsEnCours($this->org->id, now()));

        $demain = now()->addDay();
        $this->recevoir($mouvement, $demain);

        $this->assertCount(1, $this->disponibilite->versementsEnCours($this->org->id, now()), 'reçu demain : encore en cours aujourd\'hui');
        $this->assertCount(0, $this->disponibilite->versementsEnCours($this->org->id, $demain), 'reçu à cette date : plus en cours');
    }
}
