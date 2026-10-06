<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\StatutSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Cycle de vie d'un support de trésorerie : brouillon → validé (actif) → désactivé. Un support est
 * créé en brouillon et INUTILISABLE partout (encaissements, mouvements, versements, position,
 * soldes d'ouverture) tant qu'il n'est pas validé ; la validation trace qui l'a faite et quand,
 * et une caisse dédiée revérifie à ce moment les conditions de sa création. Garanties côté
 * serveur — jamais seulement par l'interface.
 */
class SupportTresorerieValidationServiceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private SupportTresorerieValidationService $validation;

    private CaisseAgentService $caisses;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.valider_supports']);
        $this->validation = app(SupportTresorerieValidationService::class);
        $this->caisses = app(CaisseAgentService::class);
        $this->site = $this->user->sites()->first();
    }

    private function compte(string $numero = '571000'): CompteComptable
    {
        return CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
    }

    private function brouillonAgence(?Site $site = null, string $libelle = 'Caisse en brouillon', string $numero = '571000', string $type = 'caisse'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => ($site ?? $this->site)->id,
            'compte_comptable_id' => $this->compte($numero)->id,
            'type' => $type,
            'libelle' => $libelle,
            'actif' => false,
        ]);
    }

    private function siteSecondaire(string $nom = 'Kouria'): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'agence', 'localisation' => 'Coyah']);
    }

    // ── Validation d'un support d'agence ─────────────────────────────────────

    public function test_valider_met_un_brouillon_d_agence_en_service_et_trace_le_validateur(): void
    {
        $brouillon = $this->brouillonAgence();
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $brouillon->statut());
        $this->assertNull($brouillon->valide_le);

        $valide = $this->validation->valider($brouillon, $this->user);

        $this->assertTrue($valide->actif);
        $this->assertSame(StatutSupportTresorerie::ACTIF, $valide->statut());
        $this->assertSame($this->user->id, $valide->valide_par_id);
        $this->assertNotNull($valide->valide_le);
        $this->assertTrue($valide->valide_le->diffInSeconds(now(), true) < 5);
        $this->assertSame(StatutSupportTresorerie::ACTIF, $brouillon->fresh()->statut(), 'persisté en base');
    }

    public function test_valider_refuse_un_support_deja_valide_et_ne_change_pas_le_validateur(): void
    {
        $support = $this->validation->valider($this->brouillonAgence(), $this->user);
        $autreValidateur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.valider_supports']);
        $validePar = $support->valide_par_id;

        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($support, $autreValidateur));

        $this->assertSame($validePar, $support->fresh()->valide_par_id);
    }

    public function test_un_support_valide_puis_desactive_ne_repasse_pas_par_la_validation(): void
    {
        $support = $this->validation->valider($this->brouillonAgence(), $this->user);
        $support->update(['actif' => false]);

        $this->assertSame(StatutSupportTresorerie::INACTIF, $support->fresh()->statut());
        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($support->fresh(), $this->user));
    }

    public function test_valider_un_support_d_une_autre_organisation_est_introuvable(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $chezLAutre = CompteTresorerie::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse étrangère',
            'actif' => false,
        ]);

        try {
            $this->validation->valider($chezLAutre, $this->user);
            $this->fail('La validation d\'un support d\'une autre organisation doit échouer.');
        } catch (ModelNotFoundException) {
            $this->assertNull($chezLAutre->fresh()->valide_le);
            $this->assertFalse($chezLAutre->fresh()->actif);
        }
    }

    // ── Statut dérivé et garde-fous du modèle ────────────────────────────────

    public function test_le_statut_se_deduit_de_la_validation_et_de_l_activation(): void
    {
        $brouillon = $this->brouillonAgence();
        $actif = $this->validation->valider($this->brouillonAgence(null, 'Autre'), $this->user);
        $inactif = $this->validation->valider($this->brouillonAgence(null, 'Encore une'), $this->user);
        $inactif->update(['actif' => false]);

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $brouillon->statut());
        $this->assertSame('Brouillon', $brouillon->statut()->label());
        $this->assertSame(StatutSupportTresorerie::ACTIF, $actif->statut());
        $this->assertSame('Actif', $actif->statut()->label());
        $this->assertSame(StatutSupportTresorerie::INACTIF, $inactif->fresh()->statut());
        $this->assertSame('Inactif', $inactif->statut()->label());
    }

    public function test_le_modele_refuse_d_activer_un_brouillon_sans_passer_par_la_validation(): void
    {
        $brouillon = $this->brouillonAgence();

        try {
            $brouillon->update(['actif' => true]);
            $this->fail('Un brouillon ne doit jamais pouvoir devenir actif sans validation.');
        } catch (\LogicException) {
            $this->assertFalse($brouillon->fresh()->actif);
            $this->assertNull($brouillon->fresh()->valide_le);
        }
    }

    public function test_un_support_cree_directement_actif_est_repute_valide(): void
    {
        $direct = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $this->compte()->id,
            'type' => 'caisse',
            'libelle' => 'Créé actif',
        ]);

        $this->assertTrue($direct->fresh()->actif);
        $this->assertNotNull($direct->fresh()->valide_le);
        $this->assertNull($direct->fresh()->valide_par_id, 'pas de validateur : création programmatique hors parcours d\'écran');
        $this->assertSame(StatutSupportTresorerie::ACTIF, $direct->fresh()->statut());
    }

    // ── Caisse dédiée : conditions revérifiées à la validation ───────────────

    public function test_valider_une_caisse_dediee_la_met_en_service(): void
    {
        $agent = $this->creerAgent($this->site);
        $brouillon = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);

        $valide = $this->validation->valider($brouillon, $this->user);

        $this->assertSame(StatutSupportTresorerie::ACTIF, $valide->statut());
        $this->assertSame($agent->id, $valide->agent_id);
        $this->assertSame($this->user->id, $valide->valide_par_id);
    }

    public function test_valider_une_caisse_dediee_refuse_un_agent_desactive_entre_temps(): void
    {
        $agent = $this->creerAgent($this->site);
        $brouillon = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $agent->update(['is_active' => false]);

        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($brouillon, $this->user));

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $brouillon->fresh()->statut());
    }

    public function test_valider_une_caisse_dediee_refuse_un_agent_retire_de_l_agence_entre_temps(): void
    {
        $agent = $this->creerAgent($this->site);
        $brouillon = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $agent->sites()->detach($this->site->id);

        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($brouillon, $this->user));

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $brouillon->fresh()->statut());
    }

    public function test_deux_brouillons_du_meme_agent_ne_peuvent_pas_etre_valides_tous_les_deux(): void
    {
        $agent = $this->creerAgent($this->site);
        $premier = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $second = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);

        $this->validation->valider($premier, $this->user);

        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($second, $this->user));
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $second->fresh()->statut());
        $this->assertSame(1, CompteTresorerie::dediees()->actifs()->where('agent_id', $agent->id)->count());
    }

    public function test_une_caisse_dediee_peut_etre_validee_une_fois_l_autre_desactivee(): void
    {
        $agent = $this->creerAgent($this->site);
        $ancienne = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $nouvelle = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $ancienne = $this->validation->valider($ancienne, $this->user);

        $this->assertErreurValidationSur('statut', fn () => $this->validation->valider($nouvelle, $this->user));

        $this->caisses->mettreAJour($ancienne, ['libelle' => $ancienne->libelle, 'actif' => false]);
        $valide = $this->validation->valider($nouvelle, $this->user);

        $this->assertSame(StatutSupportTresorerie::ACTIF, $valide->statut());
        $this->assertSame(StatutSupportTresorerie::INACTIF, $ancienne->fresh()->statut());
    }

    public function test_la_creation_d_un_brouillon_reste_refusee_tant_qu_une_caisse_active_existe_pour_le_meme_agent_et_site(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $agent->id);

        $this->assertErreurValidationSur('agent_id', fn () => $this->caisses->creer($this->org->id, $this->site->id, $agent->id));
    }

    // ── Un brouillon est inutilisable partout ────────────────────────────────

    public function test_un_brouillon_est_absent_de_la_situation_mais_present_dans_la_liste_complete(): void
    {
        $brouillon = $this->brouillonAgence();
        $disponibilite = app(TresorerieDisponibiliteService::class);

        $this->assertFalse($disponibilite->situationParSupport($this->org->id, now())->pluck('compte_tresorerie_id')->contains($brouillon->id));
        $this->assertTrue($disponibilite->situationParSupport($this->org->id, now(), true)->pluck('compte_tresorerie_id')->contains($brouillon->id));

        $this->validation->valider($brouillon, $this->user);

        $this->assertTrue($disponibilite->situationParSupport($this->org->id, now())->pluck('compte_tresorerie_id')->contains($brouillon->id));
    }

    public function test_un_brouillon_ne_recoit_pas_de_solde_d_ouverture_avant_sa_validation(): void
    {
        $brouillon = $this->brouillonAgence();
        $soldes = app(SoldeOuvertureTresorerieService::class);
        $donnees = ['date_situation' => '2026-08-01', 'montant' => 1_000_000];

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $soldes->enregistrer($this->org->id, $brouillon, $donnees, $this->user->id));
        $this->assertNull($brouillon->fresh()->soldeOuverture);

        $this->validation->valider($brouillon, $this->user);

        $solde = $soldes->enregistrer($this->org->id, $brouillon->fresh(), $donnees, $this->user->id);
        $this->assertSame(1_000_000.0, (float) $solde->montant);
    }

    public function test_un_mouvement_entre_agences_refuse_un_support_en_brouillon(): void
    {
        $siege = $this->siteSecondaire('Siège');
        $caisseSiege = $this->validation->valider($this->brouillonAgence($siege, 'Caisse Siège'), $this->user);
        $brouillonAgence = $this->brouillonAgence($this->site, 'Caisse Agence en brouillon');
        $service = app(MouvementFondsService::class);

        $depuisBrouillon = fn () => $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $siege->id,
            'compte_tresorerie_origine_id' => $brouillonAgence->id,
            'montant' => 100_000,
        ], $this->user->id);
        $this->assertErreurValidationSur('compte_tresorerie_origine_id', $depuisBrouillon);

        $versBrouillon = fn () => $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'compte_tresorerie_destination_id' => $brouillonAgence->id,
            'montant' => 100_000,
        ], $this->user->id);
        $this->assertErreurValidationSur('compte_tresorerie_destination_id', $versBrouillon);
    }

    public function test_la_reception_d_un_mouvement_refuse_un_support_de_destination_en_brouillon(): void
    {
        $siege = $this->siteSecondaire('Siège');
        $caisseSiege = $this->validation->valider($this->brouillonAgence($siege, 'Caisse Siège'), $this->user);
        // garantirSoldeSuffisant() (règle du 22/09/2026) exige un solde disponible avant l'envoi.
        $this->alimenterCaisse($caisseSiege, 100_000);
        $brouillonAgence = $this->brouillonAgence($this->site, 'Caisse Agence en brouillon');
        $service = app(MouvementFondsService::class);

        $mouvement = $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'montant' => 100_000,
        ], $this->user->id);
        $mouvement = $service->envoyer($mouvement, $this->user->id);

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $service->recevoir($mouvement, $this->user->id, $brouillonAgence->id));

        $this->validation->valider($brouillonAgence, $this->user);
        $recu = $service->recevoir($mouvement->fresh(), $this->user->id, $brouillonAgence->id);
        $this->assertSame($brouillonAgence->id, $recu->compte_tresorerie_destination_id);
    }

    public function test_un_versement_refuse_une_caisse_source_ou_destination_en_brouillon(): void
    {
        $agent = $this->creerAgent($this->site);
        $caisseBrouillon = $this->caisses->creer($this->org->id, $this->site->id, $agent->id);
        $this->alimenterCaisse($caisseBrouillon, 300_000);
        $caisseAgence = $this->validation->valider($this->brouillonAgence(), $this->user);
        $service = app(MouvementFondsService::class);

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $service->verserCaisseAgent($this->org->id, $caisseBrouillon, $caisseAgence->id, 100_000, null, $this->user->id));

        $caisseAgent = $this->validation->valider($caisseBrouillon, $this->user);
        $destinationBrouillon = $this->brouillonAgence($this->site, 'Seconde caisse en brouillon');

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $service->verserCaisseAgent($this->org->id, $caisseAgent, $destinationBrouillon->id, 100_000, null, $this->user->id));
    }
}
