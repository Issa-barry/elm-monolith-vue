<?php

namespace Tests\Feature\Users;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rapports\RapportActiviteService;
use App\Support\Rapports\RapportPerimetre;
use App\Support\Vehicules\SituationPeriode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Fiche agent (docs/fiche-agent.md) : consultation du compte, Situation alignée sur le rapport
 * d'activité (ventes créées par l'agent, encaissements saisis par l'agent), Dépenses saisies par
 * l'agent, et onglets masqués selon les droits du consulteur.
 */
class FicheAgentTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private User $agent;

    private User $collegue;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-26 12:00:00');

        // $this->user : admin_entreprise (toute l'organisation).
        $this->initOrgAndUser(['users.read', 'users.update', 'rapports.read', 'rapports.read_own', 'depenses.read']);
        $this->siteA = $this->user->sites()->firstOrFail();
        $this->siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);

        $this->agent = $this->creerUtilisateurNonAdmin($this->siteA, ['users.read', 'rapports.read_own'], 'Ousmane', 'Sidibé');
        $this->collegue = $this->creerUtilisateurNonAdmin($this->siteA, ['rapports.read_own'], 'Ibrahima', 'Bah');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ────────────────────────────────────────────────────────────────

    private function vente(
        User $vendeur,
        float $montant,
        StatutFactureVente $statut = StatutFactureVente::IMPAYEE,
        string $creeLe = '2026-09-20 09:00:00',
        ?Site $site = null,
        StatutCommandeVente $statutCommande = StatutCommandeVente::LIVREE,
    ): FactureVente {
        $this->sequence++;
        $site ??= $this->siteA;
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'reference' => 'CMD-AGENT-'.$this->sequence,
            'total_commande' => $montant,
            'statut' => $statutCommande->value,
            'created_by' => $vendeur->id,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'reference' => 'FAC-AGENT-'.$this->sequence,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
        DB::table('factures_ventes')->where('id', $facture->id)->update(['created_at' => $creeLe]);

        return $facture->fresh();
    }

    private function encaisser(FactureVente $facture, User $auteur, float $montant, string $mode = 'especes', string $date = '2026-09-21'): void
    {
        Auth::login($auteur);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => $date,
            'mode_paiement' => $mode,
        ]);
        Auth::logout();
    }

    /** @return array<string, mixed> */
    private function fiche(User $consulteur, User $agent, array $query = []): array
    {
        return $this->actingAs($consulteur)
            ->get(route('users.show', $agent).($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Users/Show', false))
            ->viewData('page')['props'];
    }

    private function perimetreRapport(?array $siteIds, array $query): RapportPerimetre
    {
        return new RapportPerimetre(
            organizationId: $this->org->id,
            siteIds: $siteIds,
            agentId: $this->agent->id,
            periode: SituationPeriode::depuisRequete(Request::create('/', 'GET', $query)),
            maSituation: false,
        );
    }

    // ── Informations ─────────────────────────────────────────────────────────

    public function test_la_fiche_presente_le_compte_en_consultation(): void
    {
        $props = $this->fiche($this->user, $this->agent);

        $this->assertSame('Ousmane Sidibé', $props['user']['nom_complet']);
        $this->assertSame('manager', $props['user']['role']);
        $this->assertTrue($props['user']['is_active']);
        $this->assertSame([[
            'id' => $this->siteA->id,
            'nom' => $this->siteA->nom,
            'is_default' => true,
        ]], $props['user']['sites']);
        $this->assertTrue($props['peut_modifier']);
        $this->assertNotNull($props['situation']);
        $this->assertNotNull($props['depenses']);
    }

    public function test_un_compte_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)->get(route('users.show', $etranger))->assertForbidden();
    }

    public function test_sans_users_update_pas_de_bouton_modifier(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->siteA, ['users.read'], 'Fanta', 'Camara');

        $props = $this->fiche($lecteur, $this->agent);

        $this->assertFalse($props['peut_modifier']);
    }

    // ── Situation : mêmes chiffres que le rapport d'activité ─────────────────

    public function test_la_situation_reprend_les_chiffres_du_rapport_d_activite(): void
    {
        $variante = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 500ml'])->variantes()->first();

        $payee = $this->vente($this->agent, 100000, StatutFactureVente::PAYEE);
        $payee->commande->lignes()->create([
            'variante_id' => $variante->id, 'quantite_demandee' => 40, 'quantite_livree' => 38,
            'prix_usine_snapshot' => 1500, 'prix_vente_snapshot' => 2500, 'total_ligne' => 100000,
            'libelle_snapshot' => 'Bouteille 500ml',
        ]);
        $this->encaisser($payee, $this->agent, 100000);

        $partielle = $this->vente($this->agent, 50000, StatutFactureVente::PARTIEL);
        $this->encaisser($partielle, $this->collegue, 20000);

        // Hors chiffres : annulée, vente d'un collègue, facture d'un autre mois.
        $this->vente($this->agent, 999000, StatutFactureVente::IMPAYEE, statutCommande: StatutCommandeVente::ANNULEE);
        $venteCollegue = $this->vente($this->collegue, 70000, StatutFactureVente::IMPAYEE);
        $this->vente($this->agent, 30000, StatutFactureVente::IMPAYEE, '2026-08-10 10:00:00');

        // Encaissement saisi par l'agent sur la vente d'un collègue : compte dans ses encaissements.
        $this->encaisser($venteCollegue, $this->agent, 25000, 'virement');

        $query = ['situation_periode' => 'ce_mois'];
        $situation = $this->fiche($this->user, $this->agent, $query)['situation'];
        $attendu = app(RapportActiviteService::class)->ventes($this->perimetreRapport(null, $query), 0)['resume'];

        $this->assertEquals([
            'ca_vendu' => $attendu['facture'],
            'encaisse' => $attendu['encaisse'],
            'reste_du' => $attendu['reste'],
            'nb_ventes' => $attendu['nombre'],
        ], $situation['ventes']['kpis']);
        $this->assertEquals(['ca_vendu' => 150000, 'encaisse' => 120000, 'reste_du' => 30000, 'nb_ventes' => 2], $situation['ventes']['kpis']);

        $this->assertSame('Bouteille 500ml', $situation['ventes']['produits'][0]['libelle']);
        $this->assertSame(38, $situation['ventes']['produits'][0]['quantite']);

        $repartition = collect($situation['ventes']['paiements']['repartition'])->keyBy('code');
        $this->assertEquals(100000, $repartition['paye']['montant']);
        $this->assertEquals(50000, $repartition['partiel']['montant']);
        $this->assertEquals(30000, $repartition['partiel']['reste_a_encaisser']);
        $this->assertEquals(150000, $situation['ventes']['paiements']['total_montant']);

        // « Encaissements réalisés » = saisis par l'agent, pas l'encaissé de ses ventes.
        $this->assertEquals(125000, $situation['encaissements']['montant']);
        $this->assertSame(2, $situation['encaissements']['nombre']);
        $moyens = collect($situation['encaissements']['par_moyen'])->keyBy('cle');
        $this->assertEquals(100000, $moyens['especes']['montant']);
        $this->assertEquals(80.0, $moyens['especes']['pourcentage_montant']);
        $this->assertEquals(20.0, $moyens['virement']['pourcentage_montant']);
    }

    public function test_toute_la_periode_couvre_tout_l_historique(): void
    {
        $this->vente($this->agent, 10000, StatutFactureVente::IMPAYEE, '2024-01-05 08:00:00');
        $this->vente($this->agent, 20000, StatutFactureVente::IMPAYEE, '2026-09-26 08:00:00');

        $props = $this->fiche($this->user, $this->agent);

        $this->assertSame('tout', $props['situation_periode']['cle']);
        $this->assertSame(2, $props['situation']['ventes']['kpis']['nb_ventes']);
        $this->assertEquals(30000, $props['situation']['ventes']['kpis']['ca_vendu']);
        // Le rapport ne propose pas « Toute la période » : pas de lien vers un autre périmètre.
        $this->assertNull($props['lien_rapport']);
    }

    public function test_lien_vers_le_rapport_filtre_sur_l_agent_pour_une_periode_bornee(): void
    {
        $props = $this->fiche($this->user, $this->agent, ['situation_periode' => 'ce_mois']);

        $this->assertSame(
            route('rapports.activite', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'agent_id' => $this->agent->id], false),
            $props['lien_rapport'],
        );
    }

    // ── Situation : droits et périmètre ──────────────────────────────────────

    public function test_users_read_seul_ne_donne_ni_situation_ni_depenses(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->siteA, ['users.read'], 'Fanta', 'Camara');

        $props = $this->fiche($lecteur, $this->agent);

        $this->assertNull($props['situation']);
        $this->assertNull($props['lien_rapport']);
        $this->assertNull($props['depenses']);
    }

    public function test_rapports_read_limite_aux_agences_du_consulteur(): void
    {
        $this->vente($this->agent, 40000);
        $this->vente($this->agent, 60000, site: $this->siteB);

        $responsableA = $this->creerUtilisateurNonAdmin($this->siteA, ['users.read', 'rapports.read'], 'Mariama', 'Barry');
        $kpis = $this->fiche($responsableA, $this->agent)['situation']['ventes']['kpis'];
        $this->assertEquals(40000, $kpis['ca_vendu']);
        $this->assertSame(1, $kpis['nb_ventes']);

        // Agent ni rattaché à l'agence du consulteur, ni actif dans celle-ci : onglet masqué.
        $responsableB = $this->creerUtilisateurNonAdmin($this->siteB, ['users.read', 'rapports.read'], 'Sékou', 'Touré');
        $this->agent->sites()->detach();
        DB::table('commandes_ventes')->where('site_id', $this->siteB->id)->delete();
        DB::table('factures_ventes')->where('site_id', $this->siteB->id)->delete();
        $this->assertNull($this->fiche($responsableB, $this->agent)['situation']);
    }

    public function test_sa_propre_fiche_avec_read_own_montre_toute_son_activite(): void
    {
        $this->vente($this->agent, 40000);
        $this->vente($this->agent, 60000, site: $this->siteB);

        $props = $this->fiche($this->agent, $this->agent, ['situation_periode' => 'ce_mois']);

        $this->assertEquals(100000, $props['situation']['ventes']['kpis']['ca_vendu']);
        $this->assertSame(
            route('ma-situation', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], false),
            $props['lien_rapport'],
        );
    }

    public function test_read_own_ne_donne_pas_la_situation_d_un_collegue(): void
    {
        $this->assertNull($this->fiche($this->agent, $this->collegue)['situation']);
    }

    // ── Dépenses saisies par l'agent ─────────────────────────────────────────

    public function test_depenses_saisies_par_l_agent_tous_statuts(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Carburant', 'code' => 'carbu']);
        $depense = fn (User $auteur, float $montant) => Depense::factory()->for($this->org)->create([
            'user_id' => $auteur->id,
            'depense_type_id' => $type->id,
            'montant' => $montant,
        ]);

        $depense($this->agent, 10000)->update(['statut' => 'valide']);
        $depense($this->agent, 5000)->update(['statut' => 'soumis']);
        $depense($this->agent, 2000);
        $depense($this->collegue, 70000)->update(['statut' => 'valide']);

        $depenses = $this->fiche($this->user, $this->agent)['depenses'];

        $this->assertEquals(['montant' => 10000, 'nombre' => 1], $depenses['resume']['validees']);
        $this->assertEquals(['montant' => 5000, 'nombre' => 1], $depenses['resume']['en_attente']);
        $this->assertSame(3, $depenses['resume']['nombre']);
        $this->assertCount(3, $depenses['lignes']);
        $this->assertSame('Carburant', $depenses['lignes'][0]['type']);
    }

    // ── Mot de passe : jamais modifiable par un tiers (ADR 0015) ─────────────

    public function test_aucun_ecran_ne_permet_de_changer_le_mot_de_passe_d_un_autre_compte(): void
    {
        $this->assertFalse(Route::has('users.update-password'));

        $hash = $this->agent->password;
        $superAdmin = $this->creerSuperAdmin();

        $this->actingAs($superAdmin)
            ->put('/backoffice/users/'.$this->agent->id.'/password', [
                'password' => 'Nouveau123',
                'password_confirmation' => 'Nouveau123',
            ])
            ->assertNotFound();

        // Un champ `password` glissé dans la modification du compte est ignoré.
        $this->actingAs($superAdmin)
            ->put(route('users.update', $this->agent), [
                'prenom' => 'Ousmane',
                'nom' => 'Sidibé',
                'telephone' => '+224620000077',
                'role' => 'manager',
                'site_id' => $this->siteA->id,
                'password' => 'Nouveau123',
                'password_confirmation' => 'Nouveau123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($hash, $this->agent->fresh()->password);
        $this->assertFalse(Hash::check('Nouveau123', $this->agent->fresh()->password));
    }

    private function creerSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        return $superAdmin;
    }
}
