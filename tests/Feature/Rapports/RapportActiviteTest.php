<?php

namespace Tests\Feature\Rapports;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rapports\Export\RapportActiviteExport;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Rapport d'activité et « Ma situation » (docs/rapports.md) : périmètre imposé côté serveur
 * (permissions, agences, agent), règles de date (vente = création de la facture, encaissement =
 * date_encaissement), blocs indépendants (jamais un « reste » par différence), créances à l'état
 * actuel toutes dates, contrôle des références Mobile Money et tableau de caisse tiré du grand livre.
 */
class RapportActiviteTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private User $agentA;

    private User $agentB;

    private User $agentSiteB;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-26 12:00:00');

        $this->initOrgAndUser(['rapports.read', 'rapports.read_own', 'tresorerie.read']);
        $this->siteA = $this->user->sites()->firstOrFail();
        $this->siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);

        $this->agentA = $this->creerUtilisateurNonAdmin($this->siteA, ['rapports.read_own'], 'Moussa', 'Sidibé');
        $this->agentB = $this->creerUtilisateurNonAdmin($this->siteA, ['rapports.read_own'], 'Ibrahima', 'Bah');
        $this->agentSiteB = $this->creerUtilisateurNonAdmin($this->siteB, ['rapports.read_own'], 'Aissatou', 'Diallo');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ────────────────────────────────────────────────────────────────

    private function vente(Site $site, User $vendeur, float $montant, string $creeLe = '2026-09-26 09:00:00', StatutFactureVente $statut = StatutFactureVente::IMPAYEE, StatutCommandeVente $statutCommande = StatutCommandeVente::LIVREE): FactureVente
    {
        $this->sequence++;
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'reference' => 'CMD-TEST-'.$this->sequence,
            'total_commande' => $montant,
            'statut' => $statutCommande->value,
            'created_by' => $vendeur->id,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'reference' => 'FAC-TEST-'.$this->sequence,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
        DB::table('factures_ventes')->where('id', $facture->id)->update(['created_at' => $creeLe]);

        return $facture->fresh();
    }

    private function encaisser(FactureVente $facture, User $auteur, float $montant, string $mode = 'especes', string $date = '2026-09-26', ?string $reference = null, ?string $operateur = null): EncaissementVente
    {
        Auth::login($auteur);
        $encaissement = EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => $date,
            'mode_paiement' => $mode,
            'operateur_mobile_money' => $operateur,
            'reference_paiement' => $reference,
        ]);
        Auth::logout();

        return $encaissement;
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $route, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route($route).($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function caisseAgence(Site $site): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse principale',
        ]);
    }

    // ── Permissions et périmètre ─────────────────────────────────────────────

    public function test_sans_permission_les_ecrans_et_exports_sont_refuses(): void
    {
        $sansDroit = $this->creerUtilisateurNonAdmin($this->siteA, ['ventes.read'], 'Saa', 'Fodé');

        $this->actingAs($sansDroit)->get(route('ma-situation'))->assertForbidden();
        $this->actingAs($sansDroit)->get(route('ma-situation.export'))->assertForbidden();
        $this->actingAs($this->agentA)->get(route('rapports.activite'))->assertForbidden();
        $this->actingAs($this->agentA)->get(route('rapports.activite.export'))->assertForbidden();
    }

    public function test_ma_situation_impose_l_agent_connecte_quel_que_soit_le_parametre(): void
    {
        $this->vente($this->siteA, $this->agentA, 100_000);
        $this->vente($this->siteA, $this->agentB, 900_000);

        $props = $this->props($this->agentA, 'ma-situation', ['agent_id' => $this->agentB->id, 'site_ids' => [$this->siteB->id]]);

        $this->assertSame('ma_situation', $props['mode']);
        $this->assertSame($this->agentA->id, $props['agent']['id']);
        $this->assertSame(1, $props['rapport']['ventes']['resume']['nombre']);
        $this->assertEquals(100_000, $props['rapport']['ventes']['resume']['facture']);
        $this->assertSame([], $props['agents']);
    }

    public function test_un_non_admin_ne_voit_que_ses_agences_meme_s_il_en_demande_une_autre(): void
    {
        $responsableB = $this->creerUtilisateurNonAdmin($this->siteB, ['rapports.read'], 'Fatoumata', 'Camara');
        $this->vente($this->siteA, $this->agentA, 100_000);
        $this->vente($this->siteB, $this->agentSiteB, 250_000);

        $props = $this->props($responsableB, 'rapports.activite');
        $this->assertSame(1, $props['rapport']['ventes']['resume']['nombre']);
        $this->assertEquals(250_000, $props['rapport']['ventes']['resume']['facture']);
        $this->assertSame([$this->siteB->id], array_column($props['sites'], 'id'));

        $force = $this->props($responsableB, 'rapports.activite', ['site_ids' => [$this->siteA->id]]);
        $this->assertSame(0, $force['rapport']['ventes']['resume']['nombre']);
    }

    public function test_un_administrateur_voit_toute_l_organisation_et_filtre_par_agence_et_agent(): void
    {
        $this->vente($this->siteA, $this->agentA, 100_000);
        $this->vente($this->siteA, $this->agentB, 200_000);
        $this->vente($this->siteB, $this->agentSiteB, 400_000);

        $tout = $this->props($this->user, 'rapports.activite');
        $this->assertEquals(700_000, $tout['rapport']['ventes']['resume']['facture']);

        $agenceA = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id]]);
        $this->assertEquals(300_000, $agenceA['rapport']['ventes']['resume']['facture']);
        $this->assertContains($this->agentA->id, array_column($agenceA['agents'], 'value'));
        $this->assertNotContains($this->agentSiteB->id, array_column($agenceA['agents'], 'value'));

        $agent = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id], 'agent_id' => $this->agentB->id]);
        $this->assertEquals(200_000, $agent['rapport']['ventes']['resume']['facture']);
    }

    public function test_un_agent_hors_du_perimetre_est_ignore(): void
    {
        $responsableB = $this->creerUtilisateurNonAdmin($this->siteB, ['rapports.read'], 'Fatoumata', 'Camara');
        $this->vente($this->siteA, $this->agentA, 100_000);
        $this->vente($this->siteB, $this->agentSiteB, 250_000);

        $props = $this->props($responsableB, 'rapports.activite', ['agent_id' => $this->agentA->id]);

        $this->assertNull($props['filters']['agent_id']);
        $this->assertEquals(250_000, $props['rapport']['ventes']['resume']['facture']);
    }

    public function test_une_autre_organisation_est_invisible(): void
    {
        $autreOrg = Organization::factory()->create();
        $etranger = $this->makeUserWithPermissions($autreOrg, ['rapports.read', 'rapports.read_own']);
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Labé', 'type' => 'agence', 'localisation' => 'Labé']);
        $etranger->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);
        $this->vente($this->siteA, $this->agentA, 100_000);

        $props = $this->props($etranger, 'rapports.activite', ['site_ids' => [$this->siteA->id], 'agent_id' => $this->agentA->id]);

        $this->assertSame(0, $props['rapport']['ventes']['resume']['nombre']);
        $this->assertNull($props['filters']['agent_id']);
    }

    public function test_l_export_applique_le_meme_perimetre_que_l_ecran(): void
    {
        Excel::fake();
        $this->vente($this->siteA, $this->agentA, 100_000);
        $this->vente($this->siteA, $this->agentB, 900_000);

        $this->actingAs($this->agentA)
            ->get(route('ma-situation.export', ['agent_id' => $this->agentB->id]))
            ->assertOk();

        Excel::assertDownloaded('ma-situation-2026-09-26.xlsx', function (RapportActiviteExport $export) {
            $ventes = $export->sheets()[1]->array();

            return count($ventes) === 1 && $ventes[0][3] === 'Moussa Sidibé';
        });
    }

    public function test_l_export_pdf_est_genere(): void
    {
        $this->vente($this->siteA, $this->agentA, 100_000);

        $this->actingAs($this->user)
            ->get(route('rapports.activite.export', ['format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ── Périodes ─────────────────────────────────────────────────────────────

    public function test_les_periodes_rapides_et_personnalisee_sont_resolues_cote_serveur(): void
    {
        $attendu = [
            'aujourd_hui' => ['2026-09-26', '2026-09-26'],
            'hier' => ['2026-09-25', '2026-09-25'],
            'cette_semaine' => ['2026-09-21', '2026-09-27'],
            'ce_mois' => ['2026-09-01', '2026-09-30'],
        ];
        foreach ($attendu as $cle => [$debut, $fin]) {
            $periode = $this->props($this->agentA, 'ma-situation', ['periode' => $cle])['periode'];
            $this->assertSame([$cle, $debut, $fin], [$periode['cle'], $periode['date_debut'], $periode['date_fin']], $cle);
        }

        $perso = $this->props($this->agentA, 'ma-situation', ['date_from' => '2026-09-10', 'date_to' => '2026-09-12'])['periode'];
        $this->assertSame(['personnalisee', '2026-09-10', '2026-09-12'], [$perso['cle'], $perso['date_debut'], $perso['date_fin']]);

        $invalide = $this->props($this->agentA, 'ma-situation', ['date_from' => '2026-09-12', 'date_to' => '2026-09-10'])['periode'];
        $this->assertSame('aujourd_hui', $invalide['cle']);

        $this->assertSame(['aujourd_hui', 'hier', 'cette_semaine', 'ce_mois', 'personnalisee'], array_column($perso['options'], 'value'));
    }

    // ── Ventes ───────────────────────────────────────────────────────────────

    public function test_les_ventes_suivent_la_date_de_creation_de_la_facture_et_excluent_annulations_et_retours(): void
    {
        $this->vente($this->siteA, $this->agentA, 100_000, '2026-09-26 08:00:00');
        $this->vente($this->siteA, $this->agentA, 50_000, '2026-09-26 10:00:00', StatutFactureVente::CREEE, StatutCommandeVente::A_CHARGER);
        $this->vente($this->siteA, $this->agentA, 999_000, '2026-09-25 18:00:00');
        $this->vente($this->siteA, $this->agentA, 70_000, '2026-09-26 11:00:00', StatutFactureVente::ANNULEE, StatutCommandeVente::ANNULEE);
        $this->vente($this->siteA, $this->agentA, 30_000, '2026-09-26 11:30:00', StatutFactureVente::ANNULEE, StatutCommandeVente::RETOURNEE);
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id, 'site_id' => $this->siteA->id, 'created_by' => $this->agentA->id,
            'statut' => StatutCommandeVente::BROUILLON->value, 'total_commande' => 400_000,
        ]);

        $ventes = $this->props($this->agentA, 'ma-situation')['rapport']['ventes'];

        $this->assertSame(2, $ventes['resume']['nombre']);
        $this->assertEquals(150_000, $ventes['resume']['facture']);
        $this->assertSame(2, $ventes['resume']['annulees_nombre']);
        $this->assertEquals(100_000, $ventes['resume']['annulees_montant']);
        $this->assertSame(['creee', 'impayee'], collect($ventes['lignes'])->pluck('statut')->sort()->values()->all());
    }

    public function test_l_encaisse_des_ventes_est_l_etat_actuel_meme_si_paye_apres_la_periode(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $facture = $this->vente($this->siteA, $this->agentA, 100_000, '2026-09-25 09:00:00');
        $this->encaisser($facture, $this->agentA, 40_000, 'especes', '2026-09-26');

        $ventes = $this->props($this->agentA, 'ma-situation', ['periode' => 'hier'])['rapport']['ventes'];

        $this->assertEquals(40_000, $ventes['resume']['encaisse']);
        $this->assertEquals(60_000, $ventes['resume']['reste']);
    }

    // ── Encaissements ────────────────────────────────────────────────────────

    public function test_les_encaissements_suivent_leur_auteur_et_leur_date_quelle_que_soit_la_vente(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentB->id);
        $ancienne = $this->vente($this->siteA, $this->agentA, 300_000, '2026-08-01 09:00:00');
        $this->encaisser($ancienne, $this->agentB, 120_000, 'especes', '2026-09-26');

        $vendeur = $this->props($this->agentA, 'ma-situation')['rapport'];
        $this->assertSame(0, $vendeur['ventes']['resume']['nombre']);
        $this->assertSame(0, $vendeur['encaissements']['resume']['nombre']);

        $encaisseur = $this->props($this->agentB, 'ma-situation')['rapport'];
        $this->assertSame(1, $encaisseur['encaissements']['resume']['nombre']);
        $this->assertEquals(120_000, $encaisseur['encaissements']['resume']['montant']);
        $this->assertSame(0, $encaisseur['creances']['resume']['nombre'], 'La créance reste celle du vendeur.');
        $this->assertSame(1, $vendeur['creances']['resume']['nombre']);
        $this->assertEquals(180_000, $vendeur['creances']['resume']['reste']);
    }

    public function test_les_encaissements_sont_totalises_par_moyen_reellement_utilise(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $facture = $this->vente($this->siteA, $this->agentA, 1_000_000);
        $this->encaisser($facture, $this->agentA, 100_000, 'especes');
        $this->encaisser($facture, $this->agentA, 250_000, 'mobile_money', '2026-09-26', 'OM-1', 'orange_money');
        $this->encaisser($facture, $this->agentA, 50_000, 'mobile_money', '2026-09-26', 'OM-2', 'orange_money');
        $this->encaisser($facture, $this->agentA, 75_000, 'mobile_money', '2026-09-26', 'KU-1', 'kulu');

        $encaissements = $this->props($this->agentA, 'ma-situation')['rapport']['encaissements'];

        $this->assertEquals(475_000, $encaissements['resume']['montant']);
        $parMoyen = collect($encaissements['par_moyen'])->pluck('montant', 'libelle')->map(fn ($m) => (float) $m)->all();
        $this->assertEquals(['Espèces' => 100_000.0, 'Kulu' => 75_000.0, 'Orange Money' => 300_000.0], $parMoyen);
    }

    public function test_un_encaissement_saisi_un_autre_jour_est_signale(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $facture = $this->vente($this->siteA, $this->agentA, 100_000, '2026-09-24 09:00:00');
        $this->encaisser($facture, $this->agentA, 10_000, 'especes', '2026-09-25');

        $hier = $this->props($this->agentA, 'ma-situation', ['periode' => 'hier'])['rapport']['encaissements'];

        $this->assertSame(1, $hier['resume']['nombre']);
        $this->assertTrue($hier['lignes'][0]['saisie_differee']);
        $this->assertSame('2026-09-26 12:00', $hier['lignes'][0]['saisi_le']);
    }

    // ── Créances ─────────────────────────────────────────────────────────────

    public function test_les_creances_sont_l_etat_actuel_toutes_dates_confondues(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $vieille = $this->vente($this->siteA, $this->agentA, 500_000, '2026-06-01 09:00:00');
        $partielle = $this->vente($this->siteA, $this->agentA, 200_000, '2026-09-20 09:00:00');
        $this->encaisser($partielle, $this->agentA, 50_000, 'especes', '2026-09-20');
        $payee = $this->vente($this->siteA, $this->agentA, 80_000, '2026-09-26 09:00:00');
        $this->encaisser($payee, $this->agentA, 80_000, 'especes');

        $creances = $this->props($this->agentA, 'ma-situation')['rapport']['creances'];

        $this->assertSame(2, $creances['resume']['nombre']);
        $this->assertSame(1, $creances['resume']['impayees']);
        $this->assertSame(1, $creances['resume']['partielles']);
        $this->assertEquals(650_000, $creances['resume']['reste']);
        $this->assertSame($vieille->reference, $creances['lignes'][0]['reference'], 'La plus ancienne en premier.');
        $this->assertSame(117, $creances['lignes'][0]['anciennete_jours']);
    }

    // ── Mobile Money ─────────────────────────────────────────────────────────

    public function test_les_references_mobile_money_absentes_ou_deja_utilisees_sont_signalees(): void
    {
        $facture = $this->vente($this->siteA, $this->agentA, 2_000_000);
        $factureB = $this->vente($this->siteB, $this->agentSiteB, 500_000, '2026-09-10 09:00:00');

        $ok = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', 'OM-UNIQUE', 'orange_money');
        $doublon = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', ' om-777 ', 'orange_money');
        $this->encaisser($factureB, $this->agentSiteB, 100_000, 'mobile_money', '2026-09-10', 'OM-777', 'orange_money');
        $autreOperateur = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', 'OM-UNIQUE', 'kulu');
        $sansReference = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', null, 'orange_money');
        $ancien = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', null, 'orange_money');
        DB::table('encaissements_ventes')->where('id', $ancien->id)->update(['created_at' => '2026-09-10 10:00:00']);

        $mm = $this->props($this->agentA, 'ma-situation')['rapport']['mobile_money'];
        $parId = collect($mm['lignes'])->keyBy('id');

        $this->assertNull($parId[$ok->id]['anomalie']);
        $this->assertNull($parId[$autreOperateur->id]['anomalie'], 'Même référence, autre opérateur : pas un doublon.');
        $this->assertSame('reference_dupliquee', $parId[$doublon->id]['anomalie']);
        $this->assertSame([], $parId[$doublon->id]['autres_utilisations'], "L'autre utilisation est hors du périmètre de l'agent.");
        $this->assertSame(1, $parId[$doublon->id]['autres_hors_perimetre']);
        $this->assertSame('reference_absente', $parId[$sansReference->id]['anomalie']);
        $this->assertSame('anterieure_obligation', $parId[$ancien->id]['anomalie']);
        $this->assertSame([1, 1, 1], [$mm['resume']['reference_absente'], $mm['resume']['reference_dupliquee'], $mm['resume']['anterieure_obligation']]);

        $admin = collect($this->props($this->user, 'rapports.activite')['rapport']['mobile_money']['lignes'])->keyBy('id');
        $this->assertSame($factureB->reference, $admin[$doublon->id]['autres_utilisations'][0]['facture_reference']);
    }

    // ── Caisse ───────────────────────────────────────────────────────────────

    public function test_le_tableau_de_caisse_part_du_solde_reporte_et_non_des_seuls_mouvements_de_la_periode(): void
    {
        Carbon::setTestNow('2026-09-25 08:00:00');
        $caisseAgence = $this->caisseAgence($this->siteA);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);

        Carbon::setTestNow('2026-09-25 12:00:00');
        $samedi = $this->vente($this->siteA, $this->agentA, 500_000, '2026-09-25 09:00:00');
        $this->encaisser($samedi, $this->agentA, 500_000, 'especes', '2026-09-25');

        Carbon::setTestNow('2026-09-26 12:00:00');
        $lundi = $this->vente($this->siteA, $this->agentA, 300_000, '2026-09-26 09:00:00');
        $this->encaisser($lundi, $this->agentA, 300_000, 'especes', '2026-09-26');
        app(MouvementFondsService::class)->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 600_000, 'Versement du jour', $this->agentA->id);

        $fiche = $this->props($this->agentA, 'ma-situation')['rapport']['caisse']['fiches'][0];

        $this->assertEquals(500_000, $fiche['solde_debut']);
        $this->assertEquals(300_000, $fiche['total_entrees']);
        $this->assertEquals(600_000, $fiche['total_sorties']);
        $this->assertEquals(200_000, $fiche['solde_fin']);
        $this->assertNotEquals(300_000 - 600_000, $fiche['solde_fin'], 'Jamais « encaissements − versements de la période ».');
        $this->assertEquals(
            app(TresorerieDisponibiliteService::class)->soldePourSupport($caisse, Carbon::parse('2026-09-26')),
            $fiche['solde_fin'],
        );
        $this->assertSame(['encaissements', 'versements_envoyes'], array_column($fiche['mouvements'], 'categorie'));
        $this->assertSame(['nombre' => 1, 'montant' => 600000.0], [
            'nombre' => $fiche['en_cours']['nombre'], 'montant' => (float) $fiche['en_cours']['montant'],
        ]);
        $this->assertEquals(200_000, end($fiche['ecritures'])['solde']);
        $this->assertSame('2026-09-26', $fiche['dernier_versement']['date_envoi']);
        $this->assertSame(0, $fiche['dernier_versement']['anciennete_jours']);
    }

    public function test_un_versement_renvoye_revient_dans_la_caisse_sous_sa_propre_categorie(): void
    {
        $caisseAgence = $this->caisseAgence($this->siteA);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $facture = $this->vente($this->siteA, $this->agentA, 400_000);
        $this->encaisser($facture, $this->agentA, 400_000, 'especes');

        $service = app(MouvementFondsService::class);
        $versement = $service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 400_000, 'Versement', $this->agentA->id);
        $service->contester($versement, $this->user->id, 'Montant compté différent');
        $service->confirmerRetour($versement->fresh(), $this->agentB->id, 'Retour à l’agent');

        $fiche = $this->props($this->agentA, 'ma-situation')['rapport']['caisse']['fiches'][0];

        $this->assertEquals(400_000, $fiche['solde_fin']);
        $this->assertContains('versements_renvoyes', array_column($fiche['mouvements'], 'categorie'));
        $this->assertSame('retourne', $fiche['versements_periode'][0]['statut']);
    }

    public function test_un_agent_sans_caisse_dediee_n_a_pas_de_solde_a_zero(): void
    {
        $caisse = $this->props($this->agentB, 'ma-situation')['rapport']['caisse'];

        $this->assertTrue($caisse['aucune_caisse']);
        $this->assertSame([], $caisse['fiches']);
    }

    public function test_la_vue_agence_liste_une_caisse_par_agent_sans_le_detail_des_ecritures(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->creerCaisseActive($this->siteA->id, $this->agentB->id);
        $this->creerCaisseActive($this->siteB->id, $this->agentSiteB->id);

        $caisse = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id]])['rapport']['caisse'];

        $this->assertFalse($caisse['detail']);
        $this->assertSame(['Ibrahima Bah', 'Moussa Sidibé'], array_column(array_column($caisse['fiches'], 'caisse'), 'agent_nom'));
        $this->assertNull($caisse['fiches'][0]['ecritures']);
    }
}
