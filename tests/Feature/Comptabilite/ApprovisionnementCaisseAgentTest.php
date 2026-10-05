<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Approvisionnement de la caisse d'un agent depuis la caisse de l'agence (ADR 0018) : envoi par
 * `tresorerie.envoyer` sur son agence ; à la réception, seul l'agent titulaire de la caisse
 * destinataire confirme ou conteste, jamais un tiers, un administrateur ni un super administrateur à
 * sa place ; un responsable qui approvisionne sa propre caisse confirme lui-même s'il a
 * `tresorerie.recevoir` ; soldes (caisse de l'agence débitée à l'envoi, caisse de l'agent créditée à
 * la confirmation seulement), retour constaté côté agence, écrans Supports, Mouvements et
 * Ma situation.
 */
class ApprovisionnementCaisseAgentTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseAgent;

    /** Agent bénéficiaire : aucune permission de trésorerie, seulement « Ma situation ». */
    private User $agent;

    /** Remettant : responsable de l'agence, `tresorerie.envoyer`. */
    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.rejeter', 'tresorerie.confirmer_retour']);
        $this->site = $this->user->sites()->first();

        $this->caisseAgence = $this->creerCaisseAgence($this->site, 'Caisse Matoto');
        $this->alimenterCaisse($this->caisseAgence, 10_000_000);

        $this->agent = $this->creerUtilisateurNonAdmin($this->site, ['rapports.read_own'], 'Moussa', 'Sidibé');
        $this->caisseAgent = $this->creerCaisseActive($this->site->id, $this->agent->id);

        $this->responsable = $this->creerUtilisateurNonAdmin(
            $this->site,
            ['tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.rejeter', 'tresorerie.confirmer_retour', 'rapports.read_own'],
            'Ousmane',
            'Camara',
        );
    }

    private function creerCaisseAgence(Site $site, string $libelle, string $numeroCompte = '571000', string $type = 'caisse'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', $numeroCompte)->firstOrFail()->id,
            'type' => $type,
            'libelle' => $libelle,
            'actif' => true,
        ]);
    }

    private function url(CompteTresorerie $caisse): string
    {
        return route('comptabilite.tresorerie.supports.approvisionner', $caisse);
    }

    /** @return array<string, mixed> */
    private function payload(array $surcharge = []): array
    {
        return array_merge([
            'compte_tresorerie_destination_id' => $this->caisseAgent->id,
            'montant' => 2_000_000,
            'motif' => 'Paiement commissions',
        ], $surcharge);
    }

    private function approvisionnementEnvoye(?User $remettant = null): MouvementFonds
    {
        return app(MouvementFondsService::class)->approvisionnerCaisseAgent(
            $this->org->id, $this->caisseAgence, $this->caisseAgent->id, 2_000_000, 'Paiement commissions', ($remettant ?? $this->responsable)->id,
        );
    }

    private function solde(CompteTresorerie $caisse): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($caisse->fresh());
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);

        return $superAdmin;
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $route): array
    {
        return $this->actingAs($user)->get(route($route))->assertOk()->viewData('page')['props'];
    }

    // ── Envoi ────────────────────────────────────────────────────────────────

    public function test_le_responsable_approvisionne_la_caisse_d_un_agent(): void
    {
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($this->caisseAgence), $this->payload())
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $mouvement = MouvementFonds::firstOrFail();
        $this->assertSame(NatureMouvementFonds::APPROVISIONNEMENT_CAISSE, $mouvement->nature);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertSame($this->responsable->id, $mouvement->sent_by);
        $this->assertNotNull($mouvement->sent_at, "l'heure de remise est tracée");
        $this->assertSame($this->caisseAgence->id, $mouvement->compte_tresorerie_origine_id);
        $this->assertSame($this->caisseAgent->id, $mouvement->compte_tresorerie_destination_id);
        $this->assertSame($this->agent->id, $mouvement->beneficiaireId());

        // Caisse de l'agence débitée à l'envoi ; caisse de l'agent PAS encore créditée.
        $this->assertEquals(8_000_000, $this->solde($this->caisseAgence));
        $this->assertEquals(0, $this->solde($this->caisseAgent));
    }

    public function test_sans_tresorerie_envoyer_l_approvisionnement_est_refuse(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.verser', 'tresorerie.recevoir']);

        $this->actingAs($lecteur)->post($this->url($this->caisseAgence), $this->payload())->assertForbidden();
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_un_utilisateur_d_une_autre_agence_ou_organisation_ne_peut_pas_approvisionner(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $etranger = $this->creerUtilisateurNonAdmin($autreSite, ['tresorerie.read', 'tresorerie.envoyer']);
        $this->actingAs($etranger)->post($this->url($this->caisseAgence), $this->payload())->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['tresorerie.envoyer']);
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $intrus->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($intrus)->post($this->url($this->caisseAgence), $this->payload())->assertForbidden();

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_la_caisse_d_un_agent_ne_peut_pas_approvisionner(): void
    {
        $this->actingAs($this->responsable)->post($this->url($this->caisseAgent), $this->payload())->assertForbidden();
    }

    public function test_une_banque_de_l_agence_ne_peut_pas_approvisionner(): void
    {
        $banque = $this->creerCaisseAgence($this->site, 'UBA', '521000', 'banque');

        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($banque), $this->payload())
            ->assertSessionHasErrors('compte_tresorerie_id');
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_la_destination_doit_etre_la_caisse_active_d_un_agent_de_la_meme_agence(): void
    {
        $envoyer = fn (array $surcharge) => $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($this->caisseAgence), $this->payload($surcharge));

        $autreCaisseAgence = $this->creerCaisseAgence($this->site, 'Caisse secondaire');
        $envoyer(['compte_tresorerie_destination_id' => $autreCaisseAgence->id])->assertSessionHasErrors('compte_tresorerie_destination_id');

        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $caisseAilleurs = $this->creerCaisseActive($autreSite->id, $this->creerAgent($autreSite, 'Bakary', 'Camara')->id);
        $envoyer(['compte_tresorerie_destination_id' => $caisseAilleurs->id])->assertSessionHasErrors('compte_tresorerie_destination_id');

        $caisseInactive = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site, 'Saa', 'Fodé')->id);
        app(CaisseAgentService::class)->mettreAJour($caisseInactive, ['libelle' => $caisseInactive->libelle, 'actif' => false]);
        $envoyer(['compte_tresorerie_destination_id' => $caisseInactive->id])->assertSessionHasErrors('compte_tresorerie_destination_id');

        $this->assertSame(0, MouvementFonds::count());
    }

    /** Révision du 04/10/2026 : le responsable gère sa caisse et celle de l'agence. */
    public function test_le_responsable_approvisionne_sa_propre_caisse_et_confirme_lui_meme(): void
    {
        $saCaisse = $this->creerCaisseActive($this->site->id, $this->responsable->id);

        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($this->caisseAgence), $this->payload(['compte_tresorerie_destination_id' => $saCaisse->id]))
            ->assertSessionHasNoErrors();
        $mouvement = MouvementFonds::firstOrFail();
        $this->assertEquals(0, $this->solde($saCaisse), 'pas encore crédité avant sa confirmation');

        $enAttente = $this->props($this->responsable, 'ma-situation');
        $this->assertSame(1, $enAttente['approvisionnements_a_confirmer']);
        $this->assertSame($mouvement->id, $enAttente['approvisionnements_en_attente'][0]['id']);

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertSame($this->responsable->id, $mouvement->received_by);
        $this->assertTrue($mouvement->confirmeParExpediteur(), 'auto-confirmation tracée');
        $this->assertEquals(2_000_000, $this->solde($saCaisse));
        $this->assertEquals(8_000_000, $this->solde($this->caisseAgence));
    }

    public function test_sans_tresorerie_recevoir_on_n_approvisionne_pas_sa_propre_caisse(): void
    {
        $envoyeur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.envoyer'], 'Fodé', 'Envoyeur');
        $saCaisse = $this->creerCaisseActive($this->site->id, $envoyeur->id);

        $this->actingAs($envoyeur)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($this->caisseAgence), $this->payload(['compte_tresorerie_destination_id' => $saCaisse->id]))
            ->assertSessionHasErrors('compte_tresorerie_destination_id');
        $this->assertSame(0, MouvementFonds::count());

        $destinations = collect($this->props($envoyeur, 'comptabilite.tresorerie.supports.index')['destinations_approvisionnement'])->pluck('id')->all();
        $this->assertNotContains($saCaisse->id, $destinations, "sa caisse n'est pas proposée");
    }

    /** Le remettant qui a perdu `tresorerie.recevoir` après l'envoi ne confirme plus lui-même. */
    public function test_le_remettant_sans_tresorerie_recevoir_ne_confirme_pas_sa_propre_caisse(): void
    {
        $saCaisse = $this->creerCaisseActive($this->site->id, $this->responsable->id);
        $mouvement = app(MouvementFondsService::class)->approvisionnerCaisseAgent(
            $this->org->id, $this->caisseAgence, $saCaisse->id, 2_000_000, null, $this->responsable->id,
        );
        $this->responsable->revokePermissionTo('tresorerie.recevoir');

        $this->expectException(ValidationException::class);
        app(MouvementFondsService::class)->recevoir($mouvement, $this->responsable->id, $saCaisse->id);
    }

    public function test_un_super_administrateur_approvisionne_sa_propre_caisse_et_confirme_lui_meme(): void
    {
        $superAdmin = $this->superAdmin();
        $saCaisse = $this->creerCaisseActive($this->site->id, $superAdmin->id);
        $mouvement = app(MouvementFondsService::class)->approvisionnerCaisseAgent(
            $this->org->id, $this->caisseAgence, $saCaisse->id, 2_000_000, null, $superAdmin->id,
        );

        app(MouvementFondsService::class)->recevoir($mouvement, $superAdmin->id, $saCaisse->id);

        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->fresh()->statut);
        $this->assertEquals(2_000_000, $this->solde($saCaisse));
    }

    public function test_un_solde_insuffisant_bloque_l_approvisionnement(): void
    {
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->url($this->caisseAgence), $this->payload(['montant' => 12_000_000]))
            ->assertSessionHasErrors('montant');

        $this->assertSame(0, MouvementFonds::count());
        $this->assertEquals(10_000_000, $this->solde($this->caisseAgence));
    }

    // ── Réception : seul l'agent bénéficiaire ────────────────────────────────

    public function test_seul_l_agent_beneficiaire_confirme_la_reception_meme_sans_permission_de_tresorerie(): void
    {
        $mouvement = $this->approvisionnementEnvoye();

        $this->actingAs($this->agent)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertSame($this->responsable->id, $mouvement->sent_by, 'remis par');
        $this->assertSame($this->agent->id, $mouvement->received_by, 'reçu par');
        $this->assertNotNull($mouvement->received_at, "l'heure de réception est tracée");
        $this->assertEquals(2_000_000, $this->solde($this->caisseAgent));
        $this->assertEquals(8_000_000, $this->solde($this->caisseAgence));
    }

    public function test_le_remettant_ne_confirme_pas_a_la_place_de_l_agent_meme_avec_tresorerie_recevoir(): void
    {
        $mouvement = $this->approvisionnementEnvoye();

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertForbidden();

        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);
        $this->assertEquals(0, $this->solde($this->caisseAgent));
    }

    public function test_ni_un_tiers_ni_un_administrateur_ne_confirment_a_la_place_de_l_agent(): void
    {
        $mouvement = $this->approvisionnementEnvoye();
        $tiers = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.recevoir', 'tresorerie.rejeter'], 'Ibrahima', 'Caissier');

        $this->actingAs($tiers)->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))->assertForbidden();
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))->assertForbidden();

        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);
    }

    /** Le Gate::before du super admin passe la policy : le service refuse quand même. */
    public function test_un_super_administrateur_ne_confirme_pas_a_la_place_de_l_agent(): void
    {
        $mouvement = $this->approvisionnementEnvoye();

        $this->actingAs($this->superAdmin())
            ->from(route('comptabilite.tresorerie.mouvements.index'))
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasErrors('mouvement');

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertNull($mouvement->received_by);
        $this->assertEquals(0, $this->solde($this->caisseAgent));
    }

    public function test_un_super_administrateur_remettant_ne_confirme_pas_pour_l_agent(): void
    {
        $superAdmin = $this->superAdmin();
        $mouvement = $this->approvisionnementEnvoye($superAdmin);

        $this->expectException(ValidationException::class);
        app(MouvementFondsService::class)->recevoir($mouvement, $superAdmin->id, $this->caisseAgent->id);
    }

    // ── Contestation et retour ──────────────────────────────────────────────

    public function test_l_agent_conteste_puis_l_agence_constate_le_retour(): void
    {
        $mouvement = $this->approvisionnementEnvoye();

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.contester', $mouvement), ['motif' => 'Rien reçu'])
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->post(route('comptabilite.tresorerie.mouvements.contester', $mouvement), ['motif' => 'Rien reçu'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutMouvementFonds::CONTESTE, $mouvement->fresh()->statut);

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.confirmer-retour', $mouvement), ['motif' => 'Espèces revenues en caisse'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutMouvementFonds::RETOURNE, $mouvement->fresh()->statut);
        $this->assertEquals(10_000_000, $this->solde($this->caisseAgence));
        $this->assertEquals(0, $this->solde($this->caisseAgent));
    }

    public function test_l_agent_beneficiaire_ne_confirme_jamais_le_retour_lui_meme(): void
    {
        $this->agent->givePermissionTo(Permission::firstOrCreate(['name' => 'tresorerie.confirmer_retour', 'guard_name' => 'web']));
        $mouvement = $this->approvisionnementEnvoye();
        app(MouvementFondsService::class)->contester($mouvement, $this->agent->id, 'Rien reçu');

        $this->actingAs($this->agent)
            ->post(route('comptabilite.tresorerie.mouvements.confirmer-retour', $mouvement), ['motif' => 'Retour'])
            ->assertForbidden();

        $this->expectException(ValidationException::class);
        app(MouvementFondsService::class)->confirmerRetour($mouvement, $this->agent->id, 'Retour');
    }

    public function test_un_approvisionnement_conteste_reste_confirmable_par_l_agent(): void
    {
        $mouvement = $this->approvisionnementEnvoye();
        app(MouvementFondsService::class)->contester($mouvement, $this->agent->id, 'Pas encore reçu');

        $this->actingAs($this->agent)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->fresh()->statut);
        $this->assertEquals(2_000_000, $this->solde($this->caisseAgent));
    }

    public function test_une_caisse_d_agent_ne_se_desactive_pas_pendant_un_approvisionnement(): void
    {
        $this->approvisionnementEnvoye();

        $this->expectException(ValidationException::class);
        app(CaisseAgentService::class)->mettreAJour($this->caisseAgent, ['libelle' => $this->caisseAgent->libelle, 'actif' => false]);
    }

    // ── Écrans ──────────────────────────────────────────────────────────────

    public function test_l_ecran_supports_propose_l_approvisionnement_et_affiche_l_attente(): void
    {
        $saCaisse = $this->creerCaisseActive($this->site->id, $this->responsable->id);
        $this->approvisionnementEnvoye();

        $props = $this->props($this->responsable, 'comptabilite.tresorerie.supports.index');
        $comptes = collect($props['comptes'])->keyBy('id');

        $this->assertTrue($comptes[$this->caisseAgence->id]['peut_approvisionner']);
        $this->assertFalse($comptes[$this->caisseAgent->id]['peut_approvisionner'], "la caisse d'un agent n'approvisionne pas");
        $this->assertEquals(2_000_000, $comptes[$this->caisseAgent->id]['en_cours_approvisionnement']);
        $this->assertSame(1, $comptes[$this->caisseAgent->id]['approvisionnements_en_cours']);

        $destinations = collect($props['destinations_approvisionnement'])->keyBy('id');
        $this->assertFalse($destinations[$this->caisseAgent->id]['est_ma_caisse']);
        $this->assertTrue($destinations[$saCaisse->id]['est_ma_caisse'], 'sa propre caisse, avec tresorerie.recevoir');

        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);
        $this->assertFalse(collect($this->props($lecteur, 'comptabilite.tresorerie.supports.index')['comptes'])
            ->firstWhere('id', $this->caisseAgence->id)['peut_approvisionner']);
    }

    public function test_l_ecran_mouvements_reserve_les_actions_a_l_agent_beneficiaire(): void
    {
        $this->agent->givePermissionTo(Permission::firstOrCreate(['name' => 'tresorerie.read', 'guard_name' => 'web']));
        $mouvement = $this->approvisionnementEnvoye();
        $ligne = fn (User $u) => collect($this->props($u, 'comptabilite.tresorerie.mouvements.index')['mouvements']['data'])->firstWhere('id', $mouvement->id);

        $chezAgent = $ligne($this->agent);
        $this->assertSame('approvisionnement_caisse', $chezAgent['nature']);
        $this->assertSame('Approvisionnement de caisse', $chezAgent['nature_label']);
        $this->assertSame($this->agent->name, $chezAgent['beneficiaire']);
        $this->assertSame($this->responsable->name, $chezAgent['expediteur']);
        $this->assertNotNull($chezAgent['envoye_le']);
        $this->assertTrue($chezAgent['peut_recevoir']);
        $this->assertTrue($chezAgent['peut_contester']);

        foreach ([$this->responsable, $this->user, $this->superAdmin()] as $autre) {
            $chezAutre = $ligne($autre);
            $this->assertFalse($chezAutre['peut_recevoir'], "{$autre->name} ne confirme pas à la place de l'agent");
            $this->assertFalse($chezAutre['peut_contester']);
        }
    }

    public function test_ma_situation_liste_les_approvisionnements_a_confirmer_et_le_badge_les_compte(): void
    {
        $mouvement = $this->approvisionnementEnvoye();

        $props = $this->props($this->agent, 'ma-situation');
        $this->assertSame(1, $props['approvisionnements_a_confirmer'], 'badge du menu « Ma situation »');
        $this->assertCount(1, $props['approvisionnements_en_attente']);
        $this->assertSame($mouvement->id, $props['approvisionnements_en_attente'][0]['id']);
        $this->assertSame($this->responsable->name, $props['approvisionnements_en_attente'][0]['remis_par']);
        $this->assertTrue($props['approvisionnements_en_attente'][0]['peut_contester']);

        $chezRemettant = $this->props($this->responsable, 'ma-situation');
        $this->assertSame([], $chezRemettant['approvisionnements_en_attente'], "le remettant n'a rien à confirmer");
        $this->assertSame(0, $chezRemettant['approvisionnements_a_confirmer']);

        app(MouvementFondsService::class)->recevoir($mouvement, $this->agent->id, $this->caisseAgent->id);
        $apres = $this->props($this->agent, 'ma-situation');
        $this->assertSame([], $apres['approvisionnements_en_attente']);
        $this->assertSame(0, $apres['approvisionnements_a_confirmer']);
    }
}
