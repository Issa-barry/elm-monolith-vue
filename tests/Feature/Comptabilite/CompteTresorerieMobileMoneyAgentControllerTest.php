<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Écran Trésorerie > Supports, lot 1 de l'ADR 0027 : création, correction, validation et affichage
 * du compte Mobile Money dédié à un agent. Les règles métier sont verrouillées dans
 * CompteMobileMoneyAgentTest ; ce fichier couvre le point d'entrée HTTP et ce que l'écran reçoit.
 */
class CompteTresorerieMobileMoneyAgentControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture', 'tresorerie.valider_supports', 'tresorerie.verser', 'tresorerie.envoyer']);
        $this->site = $this->user->sites()->first();
    }

    private function urlIndex(): string
    {
        return route('comptabilite.tresorerie.supports.index');
    }

    /** @param  array<string, mixed>  $surcharge */
    private function creer(User $agent, array $surcharge = [], ?User $par = null)
    {
        return $this->actingAs($par ?? $this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), array_merge([
                'nature' => 'dediee',
                'type' => 'mobile_money',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
                'operateur_mobile_money' => 'orange_money',
                'numero' => '620 00 00 01',
            ], $surcharge));
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_store_cree_un_compte_mobile_money_d_agent_en_brouillon(): void
    {
        $agent = $this->creerAgent($this->site);
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();

        $this->creer($agent, ['compte_comptable_id' => $compteBanque->id])
            ->assertRedirect($this->urlIndex())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $compte = CompteTresorerie::dediees()->firstOrFail();
        $this->assertSame($agent->id, $compte->agent_id);
        $this->assertSame('mobile_money', $compte->type->value);
        $this->assertSame('orange_money', $compte->operateur_mobile_money->value);
        $this->assertSame('620 00 00 01', $compte->numero);
        $this->assertFalse($compte->actif);
        $this->assertSame('561100001', $compte->compte->numero, 'compte créé automatiquement, jamais celui de la requête');
    }

    public function test_store_exige_l_operateur_et_le_numero(): void
    {
        $agent = $this->creerAgent($this->site);

        $this->creer($agent, ['operateur_mobile_money' => '', 'numero' => ''])
            ->assertSessionHasErrors(['operateur_mobile_money', 'numero']);
        $this->creer($agent, ['operateur_mobile_money' => 'autre'])
            ->assertSessionHasErrors('operateur_mobile_money');

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_store_refuse_un_agent_ou_une_agence_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $agentEtranger = User::factory()->create(['organization_id' => $autreOrg->id]);
        $agentEtranger->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);

        $this->creer($agentEtranger)->assertSessionHasErrors('agent_id');
        $this->creer($this->creerAgent($this->site), ['site_id' => $siteEtranger->id])->assertSessionHasErrors('site_id');

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_store_refuse_sans_la_permission_de_gestion(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);

        $this->creer($this->creerAgent($this->site), [], $lecteur)->assertForbidden();

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_un_support_d_agence_ne_peut_pas_reprendre_le_numero_du_compte_d_un_agent(): void
    {
        $this->creerCompteMobileMoneyActif($this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620000001');
        $compteOrange = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561100')->firstOrFail();
        $donnees = [
            'nature' => 'agence',
            'site_id' => $this->site->id,
            'type' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'compte_comptable_id' => $compteOrange->id,
        ];

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [...$donnees, 'numero' => '620 000 001'])
            ->assertSessionHasErrors('numero');
        $this->assertSame(0, CompteTresorerie::agence()->count());

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [...$donnees, 'numero' => '620000099'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CompteTresorerie::agence()->count());
    }

    // ── update / valider ─────────────────────────────────────────────────────

    public function test_update_corrige_le_numero_d_un_brouillon_et_ignore_un_type_ou_un_compte_forges(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->creer($agent);
        $compte = CompteTresorerie::dediees()->firstOrFail();
        $sousCompte = $compte->compte_comptable_id;
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $compte), [
                'libelle' => 'Orange Moussa',
                'actif' => false,
                'numero' => '620000002',
                'type' => 'banque',
                'operateur_mobile_money' => 'momo',
                'compte_comptable_id' => $compteBanque->id,
                'agent_id' => $this->user->id,
            ])
            ->assertSessionHasNoErrors();

        $compte->refresh();
        $this->assertSame('Orange Moussa', $compte->libelle);
        $this->assertSame('620000002', $compte->numero);
        $this->assertSame('mobile_money', $compte->type->value);
        $this->assertSame('orange_money', $compte->operateur_mobile_money->value);
        $this->assertSame($sousCompte, $compte->compte_comptable_id);
        $this->assertSame($agent->id, $compte->agent_id);
    }

    public function test_valider_met_le_compte_en_service_et_fige_son_numero(): void
    {
        $this->creer($this->creerAgent($this->site));
        $compte = CompteTresorerie::dediees()->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.valider', $compte))
            ->assertSessionHasNoErrors();
        $this->assertTrue($compte->fresh()->actif);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $compte), ['libelle' => $compte->libelle, 'actif' => true, 'numero' => '620000002'])
            ->assertSessionHasErrors('numero');
        $this->assertSame('620 00 00 01', $compte->fresh()->numero);

        // Activer / désactiver depuis le menu n'envoie pas le numéro : jamais bloqué par lui.
        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $compte), ['libelle' => $compte->libelle, 'actif' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($compte->fresh()->actif);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_index_presente_le_compte_comme_un_support_dedie_mobile_money_sans_action_de_versement(): void
    {
        $agent = $this->creerAgent($this->site);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');
        $this->alimenterCaisse($compte, 300_000);
        // Une caisse d'agence existe : seule la nature du compte doit empêcher le versement.
        $this->creerSupportAgence($this->site->id, 'caisse', '571000', null, 'Caisse agence');

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(function (Assert $page) use ($compte, $agent) {
                $ligne = collect($page->toArray()['props']['comptes'])->firstWhere('id', $compte->id);

                $this->assertSame('dediee', $ligne['nature']);
                $this->assertSame('mobile_money', $ligne['type']);
                $this->assertSame('Orange Money', $ligne['operateur_label']);
                $this->assertSame('620000001', $ligne['numero']);
                $this->assertSame(['id' => $agent->id, 'nom' => $agent->name], $ligne['agent']);
                $this->assertSame('561100001', $ligne['compte_numero']);
                $this->assertEquals(300_000, $ligne['solde']);
                $this->assertFalse($ligne['peut_verser']);
                $this->assertFalse($ligne['peut_approvisionner']);
                $this->assertNull($ligne['solde_ouverture']);
            });
    }

    public function test_index_distingue_les_agents_equipes_d_une_caisse_et_ceux_equipes_d_un_compte_mobile_money(): void
    {
        $avecCaisse = $this->creerAgent($this->site);
        $avecCompte = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $this->creerCaisseActive($this->site->id, $avecCaisse->id);
        $this->creerCompteMobileMoneyActif($this->site->id, $avecCompte->id, 'momo', '660000001');

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->has('caisses_dediees_actives', 1)
                ->where('caisses_dediees_actives.0.agent_id', $avecCaisse->id)
                ->has('comptes_mobile_money_agents_actifs', 1)
                ->where('comptes_mobile_money_agents_actifs.0.agent_id', $avecCompte->id)
                ->where('comptes_mobile_money_agents_actifs.0.site_id', $this->site->id)
                ->where('comptes_mobile_money_agents_actifs.0.operateur_mobile_money', 'momo'));
    }

    public function test_index_filtre_les_comptes_mobile_money_des_agents(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $agent->id);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');
        $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money');

        $this->actingAs($this->user)
            ->get($this->urlIndex().'?nature=dediee&type=mobile_money')
            ->assertInertia(fn (Assert $page) => $page
                ->has('comptes', 1)
                ->where('comptes.0.id', $compte->id));
    }
}
