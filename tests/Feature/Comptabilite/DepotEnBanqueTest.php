<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Dépôt en banque (ADR 0026) : depuis « Nouveau mouvement de fonds », une caisse (espèces) ou un
 * compte Mobile Money de l'agence verse sur une banque de la MÊME agence. Créé et envoyé en une
 * action par `tresorerie.envoyer` sur l'agence ; le support source est débité à l'envoi, la banque
 * créditée à la confirmation seulement, par toute personne ayant `tresorerie.recevoir` sur l'agence
 * (l'envoyeur compris). Jamais depuis une banque ni depuis la caisse d'un agent, jamais vers un
 * support autre qu'une banque de l'agence.
 */
class DepotEnBanqueTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteTresorerie $caisse;

    private CompteTresorerie $orange;

    private CompteTresorerie $banque;

    /** Responsable de l'agence : crée, envoie et confirme. */
    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.recevoir']);
        $this->site = $this->user->sites()->first();

        $this->caisse = $this->creerSupportAgence($this->site->id, 'caisse', '571000', libelle: 'Caisse Matoto');
        $this->orange = $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money', 'Orange Money Matoto');
        $this->banque = $this->creerSupportAgence($this->site->id, 'banque', '521000', libelle: 'UBA Matoto');
        $this->alimenterCaisse($this->caisse, 10_000_000);
        $this->alimenterCaisse($this->orange, 4_000_000);

        $this->responsable = $this->creerUtilisateurNonAdmin(
            $this->site,
            ['tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.rejeter', 'tresorerie.confirmer_retour'],
            'Ousmane',
            'Camara',
        );
    }

    /** @return array<string, mixed> */
    private function payload(array $surcharge = []): array
    {
        return array_merge([
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $this->caisse->id,
            'compte_tresorerie_destination_id' => $this->banque->id,
            'montant' => 3_000_000,
            'commentaire' => 'Dépôt de la recette',
            'reference_externe' => 'BORD-4471',
        ], $surcharge);
    }

    private function deposer(array $surcharge = [], ?User $acteur = null)
    {
        return $this->actingAs($acteur ?? $this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.store'), $this->payload($surcharge));
    }

    private function depotEnvoye(?CompteTresorerie $source = null): MouvementFonds
    {
        return app(MouvementFondsService::class)->deposerEnBanque(
            $this->org->id, $source ?? $this->caisse, $this->banque->id, 3_000_000, 'Dépôt de la recette', $this->responsable->id,
        );
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh());
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $route): array
    {
        return $this->actingAs($user)->get(route($route))->assertOk()->viewData('page')['props'];
    }

    private function autreAgence(): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
    }

    // ── Envoi ────────────────────────────────────────────────────────────────

    public function test_une_caisse_de_l_agence_depose_en_banque(): void
    {
        $this->deposer()
            ->assertRedirect(route('comptabilite.tresorerie.mouvements.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $mouvement = MouvementFonds::firstOrFail();
        $this->assertSame(NatureMouvementFonds::DEPOT_BANQUE, $mouvement->nature);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut, 'créé et envoyé, sans brouillon');
        $this->assertSame($this->site->id, $mouvement->site_origine_id);
        $this->assertSame($this->site->id, $mouvement->site_destination_id);
        $this->assertSame($this->caisse->id, $mouvement->compte_tresorerie_origine_id);
        $this->assertSame($this->banque->id, $mouvement->compte_tresorerie_destination_id);
        $this->assertSame('especes', $mouvement->moyen_transfert);
        $this->assertSame('BORD-4471', $mouvement->reference_externe);
        $this->assertSame('Dépôt de la recette', $mouvement->commentaire);
        $this->assertSame($this->responsable->id, $mouvement->sent_by);
        $this->assertNotNull($mouvement->sent_at);

        // Caisse débitée à l'envoi ; banque PAS encore créditée.
        $this->assertEquals(7_000_000, $this->solde($this->caisse));
        $this->assertEquals(0, $this->solde($this->banque));
    }

    public function test_un_compte_mobile_money_de_l_agence_depose_en_banque(): void
    {
        $this->deposer(['compte_tresorerie_origine_id' => $this->orange->id, 'montant' => 1_500_000])->assertSessionHasNoErrors();

        $mouvement = MouvementFonds::firstOrFail();
        $this->assertSame(NatureMouvementFonds::DEPOT_BANQUE, $mouvement->nature);
        $this->assertSame($this->orange->id, $mouvement->compte_tresorerie_origine_id);
        $this->assertSame('mobile_money', $mouvement->moyen_transfert);
        $this->assertEquals(2_500_000, $this->solde($this->orange));
        $this->assertEquals(10_000_000, $this->solde($this->caisse), "la caisse n'est pas touchée");
    }

    public function test_le_bordereau_est_enregistre_et_supprime_si_le_depot_est_refuse(): void
    {
        Storage::fake('public');

        $this->deposer(['justificatif' => UploadedFile::fake()->create('bordereau.pdf', 120)])->assertSessionHasNoErrors();
        $chemin = MouvementFonds::firstOrFail()->justificatif_path;
        $this->assertNotNull($chemin);
        Storage::disk('public')->assertExists($chemin);

        $this->deposer(['montant' => 9_000_000, 'justificatif' => UploadedFile::fake()->create('autre.pdf', 120)])
            ->assertSessionHasErrors('montant');
        $this->assertSame(1, MouvementFonds::count());
        $this->assertSame([$chemin], Storage::disk('public')->allFiles(), 'aucun bordereau orphelin');
    }

    public function test_l_origine_est_une_caisse_ou_un_mobile_money_de_l_agence(): void
    {
        $autreBanque = $this->creerSupportAgence($this->site->id, 'banque', '521000', libelle: 'Ecobank Matoto');
        $this->deposer(['compte_tresorerie_origine_id' => $autreBanque->id])->assertSessionHasErrors('compte_tresorerie_id');

        // La caisse d'un agent se verse d'abord à la caisse de l'agence.
        $agent = $this->creerAgent($this->site);
        $caisseAgent = $this->creerCaisseActive($this->site->id, $agent->id);
        $this->deposer(['compte_tresorerie_origine_id' => $caisseAgent->id])->assertForbidden();

        $caisseAilleurs = $this->creerSupportAgence($this->autreAgence()->id, 'caisse', '571000', libelle: 'Caisse Kouria');
        $this->deposer(['compte_tresorerie_origine_id' => $caisseAilleurs->id])->assertSessionHasErrors('compte_tresorerie_origine_id');

        $this->caisse->update(['actif' => false]);
        $this->deposer()->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_la_destination_est_une_banque_active_de_la_meme_agence(): void
    {
        $caisseSecondaire = $this->creerSupportAgence($this->site->id, 'caisse', '571000', libelle: 'Caisse secondaire');
        $this->deposer(['compte_tresorerie_destination_id' => $caisseSecondaire->id])->assertSessionHasErrors('compte_tresorerie_destination_id');
        $this->deposer(['compte_tresorerie_destination_id' => $this->orange->id])->assertSessionHasErrors('compte_tresorerie_destination_id');
        $this->deposer(['compte_tresorerie_destination_id' => ''])->assertSessionHasErrors('compte_tresorerie_destination_id');

        $banqueAilleurs = $this->creerSupportAgence($this->autreAgence()->id, 'banque', '521000', libelle: 'UBA Kouria');
        $this->deposer(['compte_tresorerie_destination_id' => $banqueAilleurs->id])->assertSessionHasErrors('compte_tresorerie_destination_id');

        $this->banque->update(['actif' => false]);
        $this->deposer()->assertSessionHasErrors('compte_tresorerie_destination_id');

        $this->assertSame(0, MouvementFonds::count());
    }

    /** Un Mobile Money ne remet pas d'espèces à un agent : seule une caisse approvisionne (ADR 0018). */
    public function test_un_mobile_money_n_approvisionne_pas_la_caisse_d_un_agent(): void
    {
        $agent = $this->creerAgent($this->site);
        $caisseAgent = $this->creerCaisseActive($this->site->id, $agent->id);

        $this->deposer(['compte_tresorerie_origine_id' => $this->orange->id, 'compte_tresorerie_destination_id' => $caisseAgent->id])
            ->assertSessionHasErrors('compte_tresorerie_id');
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_un_solde_insuffisant_bloque_le_depot(): void
    {
        $this->deposer(['montant' => 10_000_001])->assertSessionHasErrors('montant');
        $this->deposer(['compte_tresorerie_origine_id' => $this->orange->id, 'montant' => 4_000_001])->assertSessionHasErrors('montant');

        $this->assertSame(0, MouvementFonds::count());
        $this->assertEquals(10_000_000, $this->solde($this->caisse));
    }

    public function test_deposer_exige_de_creer_et_d_envoyer_sur_cette_agence(): void
    {
        $sansEnvoyer = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.create', 'tresorerie.recevoir'], 'Fodé', 'Créateur');
        $this->deposer(acteur: $sansEnvoyer)->assertForbidden();

        $sansCreer = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.envoyer'], 'Saa', 'Envoyeur');
        $this->deposer(acteur: $sansCreer)->assertForbidden();

        $etranger = $this->creerUtilisateurNonAdmin($this->autreAgence(), ['tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer'], 'Ibrahima', 'Ailleurs');
        $this->deposer(acteur: $etranger)->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['tresorerie.create', 'tresorerie.envoyer']);
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $intrus->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);
        $this->deposer(acteur: $intrus)
            ->assertSessionHasErrors(['site_origine_id', 'compte_tresorerie_origine_id', 'compte_tresorerie_destination_id']);

        $this->assertSame(0, MouvementFonds::count());
    }

    // ── Réception ────────────────────────────────────────────────────────────

    public function test_l_envoyeur_confirme_lui_meme_avec_tresorerie_recevoir(): void
    {
        $mouvement = $this->depotEnvoye();

        // Aucun support à choisir : la banque est fixée à l'envoi.
        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertSame($this->responsable->id, $mouvement->received_by);
        $this->assertTrue($mouvement->confirmeParExpediteur(), 'auto-confirmation tracée');
        $this->assertEquals(3_000_000, $this->solde($this->banque));
        $this->assertEquals(7_000_000, $this->solde($this->caisse));
    }

    public function test_la_reception_exige_tresorerie_recevoir_sur_l_agence(): void
    {
        $mouvement = $this->depotEnvoye($this->orange);

        $sansRecevoir = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.envoyer'], 'Fodé', 'Envoyeur');
        $this->actingAs($sansRecevoir)->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))->assertForbidden();

        $ailleurs = $this->creerUtilisateurNonAdmin($this->autreAgence(), ['tresorerie.read', 'tresorerie.recevoir'], 'Ibrahima', 'Ailleurs');
        $this->actingAs($ailleurs)->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))->assertForbidden();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);

        $collegue = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.recevoir'], 'Mariama', 'Caissière');
        $this->actingAs($collegue)->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertFalse($mouvement->confirmeParExpediteur());
        $this->assertEquals(3_000_000, $this->solde($this->banque));
        $this->assertEquals(1_000_000, $this->solde($this->orange));
    }

    public function test_la_banque_fixee_a_l_envoi_ne_peut_pas_etre_remplacee_a_la_reception(): void
    {
        $mouvement = $this->depotEnvoye();
        $autreBanque = $this->creerSupportAgence($this->site->id, 'banque', '521000', libelle: 'Ecobank Matoto');

        // La route ignore tout support transmis : c'est la banque de l'envoi qui est créditée.
        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement), ['compte_tresorerie_destination_id' => $this->caisse->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->banque->id, $mouvement->fresh()->compte_tresorerie_destination_id);

        $second = $this->depotEnvoye();
        $this->expectException(ValidationException::class);
        app(MouvementFondsService::class)->recevoir($second, $this->responsable->id, $autreBanque->id);
    }

    public function test_un_depot_conteste_puis_retourne_recredite_le_support_d_origine(): void
    {
        $mouvement = $this->depotEnvoye();

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.contester', $mouvement), ['motif' => 'Dépôt non effectué'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutMouvementFonds::CONTESTE, $mouvement->fresh()->statut);

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.confirmer-retour', $mouvement), ['motif' => 'Espèces revenues en caisse'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutMouvementFonds::RETOURNE, $mouvement->fresh()->statut);
        $this->assertEquals(10_000_000, $this->solde($this->caisse));
        $this->assertEquals(0, $this->solde($this->banque));
    }

    // ── Écrans et calculs voisins ───────────────────────────────────────────

    public function test_l_ecran_mouvements_affiche_le_depot_et_le_signale_a_confirmer(): void
    {
        $mouvement = $this->depotEnvoye();

        $props = $this->props($this->responsable, 'comptabilite.tresorerie.mouvements.index');
        $ligne = collect($props['mouvements']['data'])->firstWhere('id', $mouvement->id);

        $this->assertSame('depot_banque', $ligne['nature']);
        $this->assertSame('Dépôt en banque', $ligne['nature_label']);
        $this->assertSame('Caisse Matoto', $ligne['compte_origine']);
        $this->assertSame('UBA Matoto', $ligne['compte_destination']);
        $this->assertSame($this->banque->id, $ligne['compte_destination_id']);
        $this->assertTrue($ligne['peut_recevoir']);
        $this->assertTrue($ligne['peut_contester']);
        $this->assertNull($ligne['beneficiaire']);
        $this->assertContains(['value' => 'depot_banque', 'label' => 'Dépôt en banque'], $props['nature_options']);
        $this->assertSame(1, $props['mouvements_fonds_a_confirmer'], 'badge du menu Mouvements');

        app(MouvementFondsService::class)->recevoir($mouvement, $this->responsable->id, $this->banque->id);
        $this->assertSame(0, $this->props($this->responsable, 'comptabilite.tresorerie.mouvements.index')['mouvements_fonds_a_confirmer']);
    }

    /** Nature distincte : ni financement entre agences, ni versement, ni approvisionnement. */
    public function test_un_depot_en_cours_ne_fausse_pas_les_calculs_des_autres_natures(): void
    {
        $this->depotEnvoye();
        $disponibilite = app(TresorerieDisponibiliteService::class);

        $this->assertEquals(0, $disponibilite->fondsEnTransitVersSite($this->org->id, $this->site->id));
        $this->assertCount(0, $disponibilite->versementsEnCours($this->org->id));
        $this->assertCount(0, $disponibilite->approvisionnementsEnCours($this->org->id));
    }
}
