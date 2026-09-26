<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\StatutSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règles métier des caisses dédiées à un agent (décision du 2026-09-19) : sous-compte
 * propre par caisse, une seule caisse active par (agent, site), agent rattaché au site
 * et à l'organisation, type/compte figés, désactivation impossible tant que la caisse
 * détient de l'argent. Garanties côté serveur — jamais seulement par l'interface.
 */
class CaisseAgentServiceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private CaisseAgentService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);
        $this->service = app(CaisseAgentService::class);
        $this->site = $this->user->sites()->first();
    }

    private function compteRacine(): CompteComptable
    {
        return CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
    }

    public function test_creer_cree_une_caisse_de_type_caisse_avec_son_propre_sous_compte(): void
    {
        $agent = $this->creerAgent($this->site);

        $caisse = $this->service->creer($this->org->id, $this->site->id, $agent->id);

        $this->assertTrue($caisse->isDediee());
        $this->assertSame($agent->id, $caisse->agent_id);
        $this->assertSame('caisse', $caisse->type->value);
        $this->assertSame('especes', $caisse->moyen_paiement_defaut);
        $this->assertFalse($caisse->actif, 'une caisse est créée en brouillon : inutilisable jusqu\'à sa validation');
        $this->assertNull($caisse->valide_le);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $caisse->statut());
        $this->assertSame("Caisse {$agent->name}", $caisse->libelle);

        $sousCompte = $caisse->compte;
        $this->assertSame('571001', $sousCompte->numero);
        $this->assertSame($this->org->id, $sousCompte->organization_id);
        $this->assertSame($this->compteRacine()->id, $sousCompte->parent_id);
        $this->assertStringContainsString($agent->name, $sousCompte->libelle);
        $this->assertStringContainsString($this->site->nom, $sousCompte->libelle);
        $this->assertNotSame($this->compteRacine()->id, $caisse->compte_comptable_id, 'jamais le compte 571000 partagé avec la caisse de l\'agence');
    }

    public function test_creer_conserve_un_libelle_renseigne(): void
    {
        $agent = $this->creerAgent($this->site);

        $caisse = $this->service->creer($this->org->id, $this->site->id, $agent->id, '  Caisse comptoir  ');

        $this->assertSame('Caisse comptoir', $caisse->libelle);
    }

    public function test_les_sous_comptes_sont_numerotes_a_la_suite(): void
    {
        $premier = $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Aïssata', 'Barry')->id);
        $second = $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Abdoulaye', 'Diallo')->id);

        $this->assertSame('571001', $premier->compte->numero);
        $this->assertSame('571002', $second->compte->numero);
    }

    public function test_la_numerotation_reprend_apres_le_plus_grand_numero_existant_et_ignore_les_numeros_hors_format(): void
    {
        foreach (['571010', '571ABC', '5710001'] as $numero) {
            CompteComptable::create(['organization_id' => $this->org->id, 'numero' => $numero, 'libelle' => "Compte {$numero}"]);
        }

        $caisse = $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site)->id);

        $this->assertSame('571011', $caisse->compte->numero);
    }

    public function test_deux_organisations_ont_chacune_leur_propre_numerotation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre agence', 'type' => 'agence', 'localisation' => 'Kindia']);
        $autreAgent = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreAgent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site)->id);
        $chezLAutre = $this->service->creer($autreOrg->id, $autreSite->id, $autreAgent->id);

        $this->assertSame('571001', $chezLAutre->compte->numero);
        $this->assertSame($autreOrg->id, $chezLAutre->compte->organization_id);
    }

    public function test_creer_refuse_un_agent_non_rattache_au_site(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $agentDeLAutreSite = $this->creerAgent($autreSite);

        $this->assertErreurValidationSur('agent_id', fn () => $this->service->creer($this->org->id, $this->site->id, $agentDeLAutreSite->id));

        $this->assertSame(0, CompteTresorerie::dediees()->count());
        $this->assertSame(0, CompteComptable::where('numero', 'like', '571%')->where('numero', '!=', '571000')->count(), 'aucun sous-compte orphelin');
    }

    public function test_creer_refuse_un_agent_desactive(): void
    {
        $agent = $this->creerAgent($this->site);
        $agent->update(['is_active' => false]);

        $this->assertErreurValidationSur('agent_id', fn () => $this->service->creer($this->org->id, $this->site->id, $agent->id));
    }

    public function test_creer_refuse_un_agent_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);
        $intrus->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->assertErreurValidationSur('agent_id', fn () => $this->service->creer($this->org->id, $this->site->id, $intrus->id));

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_creer_refuse_un_site_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $agent = $this->creerAgent($this->site);

        $this->assertErreurValidationSur('site_id', fn () => $this->service->creer($this->org->id, $siteEtranger->id, $agent->id));
    }

    public function test_une_seule_caisse_active_par_agent_et_par_site(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $agent->id);

        $this->assertErreurValidationSur('agent_id', fn () => $this->service->creer($this->org->id, $this->site->id, $agent->id));

        $this->assertSame(1, CompteTresorerie::dediees()->count());
    }

    public function test_un_agent_rattache_a_deux_sites_peut_avoir_une_caisse_par_site(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $agent = $this->creerAgent($this->site);
        $agent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);

        $this->service->creer($this->org->id, $this->site->id, $agent->id);
        $this->service->creer($this->org->id, $autreSite->id, $agent->id);

        $this->assertSame(2, CompteTresorerie::dediees()->where('agent_id', $agent->id)->count());
    }

    public function test_apres_desactivation_une_nouvelle_caisse_devient_possible(): void
    {
        $agent = $this->creerAgent($this->site);
        $premiere = $this->creerCaisseActive($this->site->id, $agent->id);
        $this->service->mettreAJour($premiere, ['libelle' => $premiere->libelle, 'actif' => false]);

        $seconde = $this->creerCaisseActive($this->site->id, $agent->id);

        $this->assertTrue($seconde->actif);
        $this->assertSame('571002', $seconde->compte->numero, 'un sous-compte n\'est jamais réattribué');
    }

    public function test_mettre_a_jour_ne_change_que_le_libelle_et_l_activation(): void
    {
        $agent = $this->creerAgent($this->site);
        $caisse = $this->creerCaisseActive($this->site->id, $agent->id);
        $compteAvant = $caisse->compte_comptable_id;

        $misAJour = $this->service->mettreAJour($caisse, ['libelle' => '  Caisse principale de Moussa ', 'actif' => true]);

        $this->assertSame('Caisse principale de Moussa', $misAJour->libelle);
        $this->assertSame($agent->id, $misAJour->agent_id);
        $this->assertSame($this->site->id, $misAJour->site_id);
        $this->assertSame($compteAvant, $misAJour->compte_comptable_id);
        $this->assertSame('caisse', $misAJour->type->value);
    }

    public function test_la_desactivation_est_refusee_tant_que_la_caisse_detient_de_l_argent(): void
    {
        $caisse = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site)->id);
        $piece = $this->alimenterCaisse($caisse, 500_000);

        $this->assertErreurValidationSur('actif', fn () => $this->service->mettreAJour($caisse, ['libelle' => $caisse->libelle, 'actif' => false]));
        $this->assertTrue($caisse->fresh()->actif);

        $this->viderCaisse($piece);

        $this->service->mettreAJour($caisse, ['libelle' => $caisse->libelle, 'actif' => false]);
        $this->assertFalse($caisse->fresh()->actif);
    }

    public function test_la_reactivation_est_refusee_si_une_autre_caisse_active_existe_pour_le_meme_agent_et_site(): void
    {
        $agent = $this->creerAgent($this->site);
        $ancienne = $this->creerCaisseActive($this->site->id, $agent->id);
        $this->service->mettreAJour($ancienne, ['libelle' => $ancienne->libelle, 'actif' => false]);
        $this->creerCaisseActive($this->site->id, $agent->id);

        $this->assertErreurValidationSur('actif', fn () => $this->service->mettreAJour($ancienne, ['libelle' => $ancienne->libelle, 'actif' => true]));
        $this->assertFalse($ancienne->fresh()->actif);
    }

    public function test_un_brouillon_ne_s_active_pas_par_un_simple_basculement(): void
    {
        $brouillon = $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site)->id);

        $this->assertErreurValidationSur('actif', fn () => $this->service->mettreAJour($brouillon, ['libelle' => $brouillon->libelle, 'actif' => true]));

        $brouillon->refresh();
        $this->assertFalse($brouillon->actif);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $brouillon->statut());
    }

    public function test_le_libelle_d_un_brouillon_reste_modifiable_sans_l_activer(): void
    {
        $brouillon = $this->service->creer($this->org->id, $this->site->id, $this->creerAgent($this->site)->id);

        $misAJour = $this->service->mettreAJour($brouillon, ['libelle' => 'Caisse comptoir', 'actif' => false]);

        $this->assertSame('Caisse comptoir', $misAJour->libelle);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $misAJour->statut());
    }

    public function test_mettre_a_jour_refuse_un_support_d_agence(): void
    {
        $agence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $this->compteRacine()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse agence',
        ]);

        $this->expectException(\LogicException::class);
        $this->service->mettreAJour($agence, ['libelle' => 'x', 'actif' => true]);
    }

    public function test_le_libelle_automatique_d_une_caisse_dediee_est_dedoublonne_sur_le_site(): void
    {
        $premier = $this->creerAgent($this->site, 'Moussa', 'Sidibé');
        $homonyme = $this->creerAgent($this->site, 'Moussa', 'Sidibé');

        $a = $this->service->creer($this->org->id, $this->site->id, $premier->id);
        $b = $this->service->creer($this->org->id, $this->site->id, $homonyme->id);

        $this->assertSame('Caisse Moussa Sidibé', $a->libelle);
        $this->assertSame('Caisse Moussa Sidibé (2)', $b->libelle);
    }
}
