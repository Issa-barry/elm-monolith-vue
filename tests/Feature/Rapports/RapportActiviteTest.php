<?php

namespace Tests\Feature\Rapports;

use App\Enums\CategorieDepense;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutDepense;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
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
 * date_encaissement), blocs indépendants (jamais un « reste » par différence), créances = factures
 * de la période encore dues (état actuel), contrôle des références Mobile Money et tableau de caisse tiré du grand livre.
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

            $titres = array_map(fn ($feuille) => $feuille->title(), $export->sheets());

            // Colonnes : Facture, Date, Client, Véhicule, Agent…
            return count($ventes) === 1 && $ventes[0][4] === 'Moussa Sidibé'
                && $titres === ['Résumé', 'Ventes', 'Encaissements', 'Dettes clients', 'Mobile Money', 'Caisse', 'Mobile Money agents', 'Charges', 'Agents'];
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

        $perso = $this->props($this->agentA, 'ma-situation', ['date_debut' => '2026-09-10', 'date_fin' => '2026-09-12'])['periode'];
        $this->assertSame(['personnalisee', '2026-09-10', '2026-09-12'], [$perso['cle'], $perso['date_debut'], $perso['date_fin']]);

        $ancienLien = $this->props($this->agentA, 'ma-situation', ['date_from' => '2026-09-10', 'date_to' => '2026-09-12'])['periode'];
        $this->assertSame(['personnalisee', '2026-09-10'], [$ancienLien['cle'], $ancienLien['date_debut']]);

        // ADR 0057 : une période invalide est refusée avec un message, jamais remplacée en silence.
        $this->actingAs($this->agentA)
            ->get(route('ma-situation', ['date_debut' => '2026-09-12', 'date_fin' => '2026-09-10']))
            ->assertSessionHasErrors('date_fin');

        // 13/11/2026 : tous les rapports proposent aussi « Mois dernier » et « Date précise ».
        $this->assertSame(['aujourd_hui', 'hier', 'cette_semaine', 'ce_mois', 'mois_precedent', 'date_precise', 'personnalisee'], array_column($perso['options'], 'value'));

        // Date précise : une seule journée (début = fin), renvoyée comme telle au filtre.
        $jour = $this->props($this->agentA, 'ma-situation', ['date_debut' => '2026-09-12', 'date_fin' => '2026-09-12']);
        $this->assertSame(['date_precise', '2026-09-12', '2026-09-12'], [$jour['periode']['cle'], $jour['periode']['date_debut'], $jour['periode']['date_fin']]);
        $this->assertSame('date_precise', $jour['filters']['periode']);
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

        $aout = ['periode' => 'personnalisee', 'date_debut' => '2026-08-01', 'date_fin' => '2026-08-31'];
        $encaisseurAout = $this->props($this->agentB, 'ma-situation', $aout)['rapport'];
        $vendeurAout = $this->props($this->agentA, 'ma-situation', $aout)['rapport'];
        $this->assertSame(0, $encaisseurAout['creances']['resume']['nombre'], 'La créance reste celle du vendeur.');
        $this->assertSame(1, $vendeurAout['creances']['resume']['nombre']);
        $this->assertEquals(180_000, $vendeurAout['creances']['resume']['reste']);
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

    public function test_les_creances_suivent_la_periode_du_filtre(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $vieille = $this->vente($this->siteA, $this->agentA, 500_000, '2026-06-01 09:00:00');
        $partielle = $this->vente($this->siteA, $this->agentA, 200_000, '2026-09-20 09:00:00');
        $this->encaisser($partielle, $this->agentA, 50_000, 'especes', '2026-09-20');
        $duJour = $this->vente($this->siteA, $this->agentA, 300_000, '2026-09-26 08:00:00');
        $payee = $this->vente($this->siteA, $this->agentA, 80_000, '2026-09-26 09:00:00');
        $this->encaisser($payee, $this->agentA, 80_000, 'especes');

        $aujourdhui = $this->props($this->agentA, 'ma-situation')['rapport']['creances'];

        $this->assertSame(1, $aujourdhui['resume']['nombre'], 'Seules les factures du jour encore dues.');
        $this->assertSame(1, $aujourdhui['resume']['impayees']);
        $this->assertSame(0, $aujourdhui['resume']['partielles']);
        $this->assertEquals(300_000, $aujourdhui['resume']['reste']);
        $this->assertSame('2026-09-26', $aujourdhui['resume']['plus_ancienne']);
        $this->assertSame([$duJour->reference], collect($aujourdhui['lignes'])->pluck('reference')->all());

        $mois = $this->props($this->agentA, 'ma-situation', ['periode' => 'ce_mois'])['rapport']['creances'];

        $this->assertSame(2, $mois['resume']['nombre']);
        $this->assertSame(1, $mois['resume']['partielles']);
        $this->assertEquals(450_000, $mois['resume']['reste']);
        $this->assertSame('2026-09-20', $mois['resume']['plus_ancienne']);

        $annee = $this->props($this->agentA, 'ma-situation', ['periode' => 'cette_annee'])['rapport']['creances'];

        $this->assertSame(3, $annee['resume']['nombre']);
        $this->assertEquals(950_000, $annee['resume']['reste']);
        $this->assertSame($vieille->reference, $annee['lignes'][0]['reference'], 'La plus ancienne en premier.');
        $this->assertSame(117, $annee['lignes'][0]['anciennete_jours']);
    }

    public function test_le_reste_d_une_dette_de_la_periode_compte_les_paiements_posterieurs(): void
    {
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $facture = $this->vente($this->siteA, $this->agentA, 100_000, '2026-09-25 09:00:00', StatutFactureVente::PARTIEL);
        $this->encaisser($facture, $this->agentA, 40_000, 'especes', '2026-09-26');

        $hier = $this->props($this->agentA, 'ma-situation', ['periode' => 'hier'])['rapport']['creances'];

        $this->assertSame(1, $hier['resume']['partielles']);
        $this->assertEquals(60_000, $hier['resume']['reste']);
    }

    // ── Mobile Money ─────────────────────────────────────────────────────────

    public function test_les_references_mobile_money_absentes_ou_deja_utilisees_sont_signalees(): void
    {
        $facture = $this->vente($this->siteA, $this->agentA, 2_000_000);
        $factureB = $this->vente($this->siteB, $this->agentSiteB, 500_000, '2026-09-10 09:00:00');

        // Depuis l'ADR 0014, la base refuse tout nouveau doublon : ceux-ci sont des doublons historiques,
        // antérieurs à la règle (référence réécrite directement, sans clé d'unicité).
        $historique = function (EncaissementVente $e, string $reference): void {
            DB::table('encaissements_ventes')->where('id', $e->id)->update(['reference_paiement' => $reference, 'cle_reference_mobile_money' => null]);
        };

        $ok = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', 'OM-UNIQUE', 'orange_money');
        $doublon = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', 'TMP-1', 'orange_money');
        $historique($doublon, ' om-777 ');
        $this->encaisser($factureB, $this->agentSiteB, 100_000, 'mobile_money', '2026-09-10', 'OM-777', 'orange_money');
        $autreOperateur = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', 'KULU-1', 'kulu');
        $sansReference = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', null, 'orange_money');
        $ancien = $this->encaisser($facture, $this->agentA, 100_000, 'mobile_money', '2026-09-26', null, 'orange_money');
        DB::table('encaissements_ventes')->where('id', $ancien->id)->update(['created_at' => '2026-09-10 10:00:00']);

        $mm = $this->props($this->agentA, 'ma-situation')['rapport']['mobile_money'];
        $parId = collect($mm['lignes'])->keyBy('id');

        $this->assertNull($parId[$ok->id]['anomalie']);
        $this->assertNull($parId[$autreOperateur->id]['anomalie']);
        $this->assertSame('reference_dupliquee', $parId[$doublon->id]['anomalie']);
        $this->assertSame([], $parId[$doublon->id]['autres_utilisations'], "L'autre utilisation est hors du périmètre de l'agent.");
        $this->assertSame(1, $parId[$doublon->id]['autres_hors_perimetre']);
        $this->assertSame('reference_absente', $parId[$sansReference->id]['anomalie']);
        $this->assertSame('anterieure_obligation', $parId[$ancien->id]['anomalie']);
        $this->assertSame([1, 1, 1], [$mm['resume']['reference_absente'], $mm['resume']['reference_dupliquee'], $mm['resume']['anterieure_obligation']]);

        // Même référence chez un autre opérateur : doublon aussi (ADR 0014 — avant, l'opérateur les distinguait).
        $historique($autreOperateur, 'om-unique');
        $parId = collect($this->props($this->agentA, 'ma-situation')['rapport']['mobile_money']['lignes'])->keyBy('id');
        $this->assertSame('reference_dupliquee', $parId[$autreOperateur->id]['anomalie']);
        $this->assertSame('reference_dupliquee', $parId[$ok->id]['anomalie']);
        $this->assertSame($ok->id, $parId[$autreOperateur->id]['autres_utilisations'][0]['id']);

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

    /** Le compte Mobile Money d'un agent (ADR 0027) n'est pas une caisse : il n'entre pas dans ce tableau. */
    public function test_le_compte_mobile_money_d_un_agent_n_apparait_pas_dans_le_tableau_de_caisse(): void
    {
        $this->alimenterCaisse($this->creerCompteMobileMoneyActif($this->siteA->id, $this->agentB->id), 250_000);
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->creerCompteMobileMoneyActif($this->siteA->id, $this->agentA->id, 'orange_money');

        $sansCaisse = $this->props($this->agentB, 'ma-situation')['rapport']['caisse'];
        $this->assertTrue($sansCaisse['aucune_caisse']);
        $this->assertSame([], $sansCaisse['fiches']);

        $agence = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id]])['rapport']['caisse'];
        $this->assertSame(['Moussa Sidibé'], array_column(array_column($agence['fiches'], 'caisse'), 'agent_nom'));
        $this->assertEquals(0, $agence['resume']['solde_fin']);
    }

    /**
     * Lot 4 de l'ADR 0027 : le compte Mobile Money de l'agent a sa propre fiche — ce qu'il détient
     * encore, ce qu'il a déjà transféré à l'agence, les frais — tirée du grand livre, comme la caisse.
     */
    public function test_le_compte_mobile_money_de_l_agent_montre_ce_qu_il_detient_et_ce_qu_il_a_transfere(): void
    {
        $compte = $this->creerCompteMobileMoneyActif($this->siteA->id, $this->agentA->id, 'orange_money');
        $compteAgence = $this->creerSupportAgence($this->siteA->id, 'mobile_money', '561100', 'orange_money');
        $facture = $this->vente($this->siteA, $this->agentA, 500_000);
        Auth::login($this->agentA);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 500_000,
            'date_encaissement' => '2026-09-26',
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'compte_tresorerie_id' => $compte->id,
            'reference_paiement' => 'OM-RAP-1',
        ]);
        Auth::logout();
        app(MouvementFondsService::class)->transfererMobileMoneyAgent($this->org->id, $compte, $compteAgence->id, 300_000, 2_000, 'OM-TR-RAP', null, $this->agentA->id);

        $rapport = $this->props($this->agentA, 'ma-situation')['rapport'];
        $fiche = $rapport['comptes_mobile_money']['fiches'][0];

        $this->assertSame('mobile_money', $fiche['caisse']['type']);
        $this->assertSame('Orange Money', $fiche['caisse']['operateur_label']);
        $this->assertEquals(500_000, $fiche['total_entrees']);
        $this->assertEquals(302_000, $fiche['total_sorties'], '300 000 transférés + 2 000 de frais');
        $this->assertEquals(198_000, $fiche['solde_fin'], 'ce que l\'agent détient encore');
        $this->assertEquals(198_000, $fiche['solde_actuel']);
        $this->assertSame(['encaissements', 'versements_envoyes', 'frais_transfert'], array_column($fiche['mouvements'], 'categorie'));
        $this->assertSame(['Encaissements Mobile Money', 'Transferts envoyés à l\'agence', 'Frais de transfert'], array_column($fiche['mouvements'], 'libelle'));
        $this->assertSame(1, $fiche['en_cours']['nombre']);
        $this->assertEquals(300_000, $fiche['en_cours']['montant'], 'montant transféré, hors frais');
        $this->assertEquals(300_000, $fiche['versements_periode'][0]['montant']);
        $this->assertSame('2026-09-26', $fiche['dernier_versement']['date_envoi']);
        $this->assertNotNull($fiche['ecritures'], 'détail des écritures dans « Ma situation »');

        // Ce n'est pas une caisse : rien dans le tableau de caisse.
        $this->assertTrue($rapport['caisse']['aucune_caisse']);

        // Vue agence : une ligne par compte, sans détail ; un agent sans compte n'a rien.
        $agence = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id]])['rapport']['comptes_mobile_money'];
        $this->assertFalse($agence['detail']);
        $this->assertSame(['Moussa Sidibé'], array_column(array_column($agence['fiches'], 'caisse'), 'agent_nom'));
        $this->assertTrue($this->props($this->agentB, 'ma-situation')['rapport']['comptes_mobile_money']['aucune_caisse']);
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

    // ── Créée à / Encaissée à (ADR 0012) ─────────────────────────────────────

    /** Encaissement reçu par `$agence`, quelle que soit l'agence de la facture. */
    private function encaisserA(FactureVente $facture, User $auteur, float $montant, Site $agence): EncaissementVente
    {
        Auth::login($auteur);
        $encaissement = EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'site_encaissement_id' => $agence->id,
            'montant' => $montant,
            'date_encaissement' => '2026-09-26',
            'mode_paiement' => 'especes',
        ]);
        Auth::logout();

        return $encaissement;
    }

    public function test_vente_et_encaissement_dans_la_meme_agence_affichent_la_meme_agence(): void
    {
        $facture = $this->vente($this->siteA, $this->agentA, 100_000);
        $this->encaisser($facture, $this->agentA, 100_000);

        $rapport = $this->props($this->user, 'rapports.activite')['rapport'];

        $vente = collect($rapport['ventes']['lignes'])->firstWhere('id', $facture->id);
        $this->assertSame($this->siteA->nom, $vente['site_nom']);
        $this->assertSame($this->siteA->nom, $vente['encaisse_a']);

        $encaissement = $rapport['encaissements']['lignes'][0];
        $this->assertSame($this->siteA->nom, $encaissement['site_nom']);
        $this->assertSame($this->siteA->nom, $encaissement['encaisse_a']);
        $this->assertFalse($encaissement['pour_autre_agence']);
        $this->assertEquals(0, $rapport['encaissements']['resume']['pour_autres_agences_montant']);
    }

    public function test_une_commande_creee_a_une_agence_et_encaissee_a_une_autre_est_tracee_des_deux_cotes(): void
    {
        $factureA = $this->vente($this->siteA, $this->agentA, 300_000);
        $this->encaisserA($factureA, $this->agentSiteB, 300_000, $this->siteB);
        $factureB = $this->vente($this->siteB, $this->agentSiteB, 50_000);
        $this->encaisser($factureB, $this->agentSiteB, 50_000);

        // Kouria (B) : elle voit ses deux encaissements, dont celui fait pour l'agence A, à reverser.
        $b = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteB->id]])['rapport'];
        $this->assertEquals(350_000, $b['encaissements']['resume']['montant']);
        $this->assertSame(1, $b['encaissements']['resume']['pour_autres_agences_nombre']);
        $this->assertEquals(300_000, $b['encaissements']['resume']['pour_autres_agences_montant']);
        $pourA = collect($b['encaissements']['lignes'])->firstWhere('facture_reference', $factureA->reference);
        $this->assertSame($this->siteA->nom, $pourA['site_nom'], 'Créée à');
        $this->assertSame('Kouria', $pourA['encaisse_a'], 'Encaissée à');
        $this->assertTrue($pourA['pour_autre_agence']);
        $propre = collect($b['encaissements']['lignes'])->firstWhere('facture_reference', $factureB->reference);
        $this->assertFalse($propre['pour_autre_agence']);

        // La vente et la créance restent à l'agence de la commande : B ne la compte pas dans ses ventes.
        $this->assertNull(collect($b['ventes']['lignes'])->firstWhere('id', $factureA->id));

        // A : la vente est chez elle, encaissée à Kouria ; l'encaissement n'est pas dans SA trésorerie.
        $a = $this->props($this->user, 'rapports.activite', ['site_ids' => [$this->siteA->id]])['rapport'];
        $vente = collect($a['ventes']['lignes'])->firstWhere('id', $factureA->id);
        $this->assertSame($this->siteA->nom, $vente['site_nom']);
        $this->assertSame('Kouria', $vente['encaisse_a']);
        $this->assertEquals(300_000, $vente['encaisse']);
        $this->assertSame([], $a['encaissements']['lignes']);
    }

    public function test_les_ventes_et_dettes_affichent_le_vehicule_de_la_commande(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Tricycle 07']);
        $avecVehicule = $this->vente($this->siteA, $this->agentA, 100_000);
        CommandeVente::whereKey($avecVehicule->commande_vente_id)->update(['vehicule_id' => $vehicule->id]);
        $sansVehicule = $this->vente($this->siteA, $this->agentA, 50_000);

        $rapport = $this->props($this->agentA, 'ma-situation')['rapport'];

        $ventes = collect($rapport['ventes']['lignes'])->keyBy('id');
        $this->assertSame('Tricycle 07', $ventes[$avecVehicule->id]['vehicule_nom']);
        $this->assertNull($ventes[$sansVehicule->id]['vehicule_nom']);
        $this->assertSame('Tricycle 07', collect($rapport['creances']['lignes'])->firstWhere('id', $avecVehicule->id)['vehicule_nom']);
    }

    public function test_ma_situation_de_l_agent_trace_l_encaissement_fait_pour_une_autre_agence(): void
    {
        $factureA = $this->vente($this->siteA, $this->agentA, 200_000);
        $this->encaisserA($factureA, $this->agentSiteB, 200_000, $this->siteB);

        $rapport = $this->props($this->agentSiteB, 'ma-situation')['rapport'];

        $ligne = $rapport['encaissements']['lignes'][0];
        $this->assertSame($this->siteA->nom, $ligne['site_nom']);
        $this->assertSame('Kouria', $ligne['encaisse_a']);
        $this->assertTrue($ligne['pour_autre_agence']);
        $this->assertEquals(200_000, $rapport['encaissements']['resume']['pour_autres_agences_montant']);
    }

    // ── Remise de l'agent à SON agence, par agent, charges (ADR 0064) ────────

    /** @return array<string, mixed>|null ligne de l'agent dans le tableau par agent du rapport d'activité */
    private function ligneAgent(User $agent, array $query = []): ?array
    {
        return collect($this->props($this->user, 'rapports.activite', $query)['rapport']['agents']['lignes'])
            ->firstWhere('agent_id', $agent->id);
    }

    private function depense(User $auteur, Site $site, float $montant, StatutDepense $statut, string $date = '2026-09-26', CategorieDepense $categorie = CategorieDepense::INTERNE): Depense
    {
        return Depense::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'user_id' => $auteur->id,
            'depense_type_id' => DepenseType::factory()->create(['organization_id' => $this->org->id, 'categorie' => $categorie->value])->id,
            'montant' => $montant,
            'date_depense' => $date,
            'statut' => $statut->value,
        ]);
    }

    /**
     * L'agent remet à SON agence (versements de caisse), jamais à la trésorerie principale :
     * reste = net à remettre − déjà remis (réception confirmée) ; une remise en transit reste dans le
     * reste ; un encaissement n'est jamais une remise. Même chiffre dans « Ma situation » et dans
     * le rapport d'activité.
     */
    public function test_remise_de_l_agent_a_son_agence_partielle_en_transit_puis_totale(): void
    {
        $caisseAgence = $this->caisseAgence($this->siteA);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 1_000_000), $this->agentA, 1_000_000);

        $avant = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(0.0, $avant['deja_remis'], 'Un encaissement n\'est jamais une remise.');
        $this->assertEquals(1_000_000, $avant['net_a_remettre']);
        $this->assertEquals(1_000_000, $avant['reste_a_remettre']);
        $this->assertSame('a_remettre', $avant['statut']);
        $this->assertSame('Non remis', $avant['statut_label']);
        $this->assertSame([$this->siteA->nom], $avant['agences']);
        $this->assertSame('2026-09-26', $avant['depuis']);

        $service = app(MouvementFondsService::class);
        $recu = $service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 400_000, 'Versement', $this->agentA->id);
        $service->recevoir($recu, $this->user->id, $caisseAgence->id);
        $enTransit = $service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 200_000, 'Versement', $this->agentA->id);

        $remise = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(400_000, $remise['deja_remis']);
        $this->assertEquals(400_000, $remise['remis']);
        $this->assertEquals(200_000, $remise['en_transit']);
        // Envoyée mais non confirmée : la remise en transit fait encore partie du reste.
        $this->assertEquals(600_000, $remise['reste_a_remettre']);
        $this->assertSame('remise_en_cours', $remise['statut']);

        // Même agent, même chiffre dans le rapport d'activité.
        $ligne = $this->ligneAgent($this->agentA);
        $this->assertSame($remise, $ligne['remise']);
        $this->assertEquals(1_000_000, $ligne['encaisse_montant']);

        $service->recevoir($enTransit, $this->user->id, $caisseAgence->id);
        $partiel = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(600_000, $partiel['deja_remis']);
        $this->assertEquals(400_000, $partiel['reste_a_remettre']);
        $this->assertEquals(0.0, $partiel['en_transit']);
        $this->assertSame('partiellement_remis', $partiel['statut']);

        $service->recevoir($service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 400_000, 'Solde', $this->agentA->id), $this->user->id, $caisseAgence->id);
        $total = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(1_000_000, $total['deja_remis']);
        $this->assertEquals(0.0, $total['reste_a_remettre']);
        $this->assertSame('remis', $total['statut']);
        $this->assertSame('Totalement remis', $total['statut_label']);
    }

    /**
     * Exemple du métier (15/11/2026) : encaissé 1 000 000, charges validées 200 000 → net 800 000 ;
     * déjà remis 500 000 → reste 300 000. Charges en attente, rejetées, annulées ou supprimées : jamais
     * déduites. Une charge validée n'est déduite qu'une fois (elle ne touche pas la caisse de l'agent).
     */
    public function test_seules_les_charges_validees_diminuent_le_montant_a_remettre(): void
    {
        $caisseAgence = $this->caisseAgence($this->siteA);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 1_000_000), $this->agentA, 1_000_000);
        $this->depense($this->agentA, $this->siteA, 200_000, StatutDepense::VALIDE);
        $this->depense($this->agentA, $this->siteA, 50_000, StatutDepense::SOUMIS);
        $this->depense($this->agentA, $this->siteA, 30_000, StatutDepense::REJETE);
        $this->depense($this->agentA, $this->siteA, 20_000, StatutDepense::ANNULE);
        $this->depense($this->agentA, $this->siteA, 70_000, StatutDepense::VALIDE)->delete();
        // Charge validée d'un AUTRE agent : jamais déduite de la remise d'agentA.
        $this->depense($this->agentB, $this->siteA, 90_000, StatutDepense::VALIDE);

        $service = app(MouvementFondsService::class);
        $service->recevoir($service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 500_000, 'Versement', $this->agentA->id), $this->user->id, $caisseAgence->id);

        $rapport = $this->props($this->agentA, 'ma-situation')['rapport'];
        $remise = $rapport['remise'];
        $this->assertEquals(1_000_000, $remise['encaisse']);
        $this->assertEquals(200_000, $remise['charges_validees']);
        $this->assertEquals(800_000, $remise['net_a_remettre']);
        $this->assertEquals(500_000, $remise['deja_remis']);
        $this->assertEquals(300_000, $remise['reste_a_remettre']);
        $this->assertSame('partiellement_remis', $remise['statut']);

        // Charges saisies (activité) et charges validées (déduites) restent deux indicateurs distincts.
        $this->assertEquals(250_000, $rapport['charges']['resume']['charges_saisies']);
        $this->assertEquals(200_000, $rapport['charges']['resume']['charges_payees']);

        // Une seule déduction : la validation ne touche pas le solde de la caisse de l'agent.
        $this->assertEquals(500_000, app(TresorerieDisponibiliteService::class)->soldePourSupport($caisse));

        // Une remise en transit ne change pas le reste, elle est affichée à part.
        $service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 100_000, 'Versement', $this->agentA->id);
        $transit = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(300_000, $transit['reste_a_remettre']);
        $this->assertEquals(100_000, $transit['en_transit']);
        $this->assertSame('remise_en_cours', $transit['statut']);
    }

    /** Les espèces remises par l'agence à l'agent (approvisionnement reçu) augmentent ce qu'il doit remettre. */
    public function test_un_approvisionnement_recu_augmente_le_net_a_remettre(): void
    {
        $caisseAgence = $this->caisseAgence($this->siteA);
        $this->alimenterCaisse($caisseAgence, 5_000_000);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $service = app(MouvementFondsService::class);
        $approvisionnement = $service->approvisionnerCaisseAgent($this->org->id, $caisseAgence, $caisse->id, 300_000, 'Fonds de charges', $this->user->id);

        $this->assertEquals(0.0, $this->props($this->agentA, 'ma-situation')['rapport']['remise']['approvisionnements'], 'Pas encore reçu par l\'agent.');

        $service->recevoir($approvisionnement, $this->agentA->id, $caisse->id);
        $this->depense($this->agentA, $this->siteA, 250_000, StatutDepense::VALIDE);

        $remise = $this->props($this->agentA, 'ma-situation')['rapport']['remise'];
        $this->assertEquals(300_000, $remise['approvisionnements']);
        $this->assertEquals(50_000, $remise['net_a_remettre']);
        $this->assertEquals(50_000, $remise['reste_a_remettre']);
    }

    /**
     * Cumul jusqu'à la fin de la période, depuis la validation de la caisse : encaissé lundi, remis
     * mardi → reste lundi = 1 000 000, reste mardi = 0 (aucun report perdu, jamais négatif) ; un
     * encaissement antérieur à la caisse dédiée n'est pas compté.
     */
    public function test_le_reste_est_cumule_jusqu_a_la_fin_de_la_periode(): void
    {
        Carbon::setTestNow('2026-09-21 10:00:00');
        $this->encaisser($this->vente($this->siteA, $this->agentA, 700_000, '2026-09-21 09:00:00'), $this->agentA, 700_000, 'mobile_money', '2026-09-21', 'OM-AVANT', 'orange_money');

        Carbon::setTestNow('2026-09-22 08:00:00');
        $caisseAgence = $this->caisseAgence($this->siteA);
        $caisse = $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 1_000_000, '2026-09-22 09:00:00'), $this->agentA, 1_000_000, 'especes', '2026-09-22');

        Carbon::setTestNow('2026-09-23 10:00:00');
        $service = app(MouvementFondsService::class);
        $service->recevoir($service->verserCaisseAgent($this->org->id, $caisse, $caisseAgence->id, 1_000_000, 'Versement de la veille', $this->agentA->id), $this->user->id, $caisseAgence->id);

        Carbon::setTestNow('2026-09-26 12:00:00');
        $lundi = $this->props($this->agentA, 'ma-situation', ['date_debut' => '2026-09-22', 'date_fin' => '2026-09-22'])['rapport']['remise'];
        $this->assertEquals(1_000_000, $lundi['encaisse'], 'L\'encaissement d\'avant la caisse dédiée n\'est pas compté.');
        $this->assertEquals(1_000_000, $lundi['reste_a_remettre']);
        $this->assertEquals(0.0, $lundi['deja_remis']);

        $mardi = $this->props($this->agentA, 'ma-situation', ['date_debut' => '2026-09-23', 'date_fin' => '2026-09-23'])['rapport']['remise'];
        $this->assertEquals(1_000_000, $mardi['deja_remis']);
        $this->assertEquals(1_000_000, $mardi['remis']);
        $this->assertEquals(0.0, $mardi['reste_a_remettre']);
        $this->assertSame('remis', $mardi['statut']);
    }

    /** Rapport par agence : la déduction des charges ne s'applique jamais à la remise de l'agence. */
    public function test_la_deduction_des_charges_ne_touche_pas_la_remise_de_l_agence(): void
    {
        $this->siteA->update(['is_central_tresorerie' => true]);
        $this->caisseAgence($this->siteB);
        $this->creerCaisseActive($this->siteB->id, $this->agentSiteB->id);
        $this->encaisser($this->vente($this->siteB, $this->agentSiteB, 600_000), $this->agentSiteB, 600_000);
        $sans = collect($this->props($this->user, 'rapports.agences')['rapport']['agences'])->firstWhere('site_id', $this->siteB->id)['remise'];

        $this->depense($this->agentSiteB, $this->siteB, 100_000, StatutDepense::VALIDE);
        $avec = collect($this->props($this->user, 'rapports.agences')['rapport']['agences'])->firstWhere('site_id', $this->siteB->id)['remise'];
        $agent = $this->props($this->agentSiteB, 'ma-situation')['rapport']['remise'];

        $this->assertEquals(500_000, $agent['reste_a_remettre'], 'Niveau agent : charge validée déduite.');
        // Niveau agence : règle ADR 0016 inchangée — la charge n'est pas déduite une seconde fois par ce rapport.
        $this->assertSame($sans['remis'], $avec['remis']);
        $this->assertSame($sans['en_transit'], $avec['en_transit']);
    }

    public function test_sans_caisse_dediee_pas_de_remise(): void
    {
        $this->encaisser($this->vente($this->siteA, $this->agentB, 500_000), $this->agentB, 500_000);

        $this->assertNull($this->props($this->agentB, 'ma-situation')['rapport']['remise']);
        $this->assertNull($this->ligneAgent($this->agentB)['remise']);
    }

    /** Espèces + Orange Money par agent ; somme des lignes = totaux du rapport ; colonnes communes. */
    public function test_encaissements_par_moyen_et_par_agent_coherents_avec_les_totaux(): void
    {
        $this->encaisser($this->vente($this->siteA, $this->agentA, 300_000), $this->agentA, 300_000);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 200_000), $this->agentA, 200_000, 'mobile_money', '2026-09-26', 'OM-AG-1', 'orange_money');
        $this->encaisser($this->vente($this->siteA, $this->agentB, 100_000), $this->agentB, 100_000);

        $rapport = $this->props($this->user, 'rapports.activite')['rapport'];
        $lignes = collect($rapport['agents']['lignes'])->keyBy('agent_id');

        $this->assertSame(['especes', 'mobile_money:orange_money'], array_column($rapport['moyens'], 'cle'));
        $this->assertEquals(300_000, $lignes[$this->agentA->id]['par_moyen']['especes']['montant']);
        $this->assertEquals(200_000, $lignes[$this->agentA->id]['par_moyen']['mobile_money:orange_money']['montant']);
        $this->assertEquals(500_000, $lignes[$this->agentA->id]['encaisse_montant']);
        $this->assertEquals(100_000, $lignes[$this->agentB->id]['encaisse_montant']);
        $this->assertEquals($rapport['encaissements']['resume']['montant'], $rapport['agents']['totaux']['encaisse_montant']);
        $this->assertEquals($rapport['ventes']['resume']['facture'], $rapport['agents']['totaux']['ventes_montant']);
        $this->assertEquals($rapport['creances']['resume']['reste'], $rapport['agents']['totaux']['dettes_montant']);

        // Ma situation : mêmes chiffres pour le même agent.
        $maSituation = $this->props($this->agentA, 'ma-situation')['rapport'];
        $this->assertEquals(500_000, $maSituation['encaissements']['resume']['montant']);
        // Ma situation : la seule ligne de l'agent connecté, mêmes chiffres que dans le rapport d'activité.
        $this->assertSame([$this->agentA->id], array_column($maSituation['agents']['lignes'], 'agent_id'));
        $this->assertEquals($lignes[$this->agentA->id]['encaisse_montant'], $maSituation['agents']['lignes'][0]['encaisse_montant']);
    }

    /**
     * Charges saisies PAR l'agent (auteur de la dépense), jamais les charges de son agence : validées
     * et en attente comptées, brouillon et rejetée exclues, période respectée.
     */
    public function test_charges_saisies_par_l_agent_et_non_celles_de_son_agence(): void
    {
        $this->depense($this->agentA, $this->siteA, 100_000, StatutDepense::VALIDE);
        $this->depense($this->agentA, $this->siteA, 50_000, StatutDepense::SOUMIS);
        $this->depense($this->agentA, $this->siteA, 10_000, StatutDepense::BROUILLON);
        $this->depense($this->agentA, $this->siteA, 20_000, StatutDepense::REJETE);
        $this->depense($this->agentA, $this->siteA, 30_000, StatutDepense::VALIDE, '2026-09-25');
        $this->depense($this->agentB, $this->siteA, 70_000, StatutDepense::VALIDE, categorie: CategorieDepense::LIVREUR);

        $charges = $this->props($this->agentA, 'ma-situation')['rapport']['charges'];
        $this->assertEquals(150_000, $charges['resume']['charges_saisies']);
        $this->assertEquals(100_000, $charges['resume']['charges_payees']);
        $this->assertEquals(50_000, $charges['resume']['charges_en_attente']);
        $this->assertCount(2, $charges['lignes']);
        $this->assertSame(2, $charges['total_lignes']);

        $hier = $this->props($this->agentA, 'ma-situation', ['periode' => 'hier'])['rapport']['charges'];
        $this->assertEquals(30_000, $hier['resume']['charges_saisies']);

        $rapport = $this->props($this->user, 'rapports.activite')['rapport'];
        $lignes = collect($rapport['agents']['lignes'])->keyBy('agent_id');
        $this->assertEquals(150_000, $lignes[$this->agentA->id]['charges_saisies']);
        $this->assertEquals(70_000, $lignes[$this->agentB->id]['charges_saisies']);
        $this->assertEquals(70_000, $lignes[$this->agentB->id]['charges_avances_tiers']);
        $this->assertEquals(220_000, $rapport['agents']['totaux']['charges_saisies']);
        $this->assertEquals(220_000, $rapport['charges']['resume']['charges_saisies']);
    }

    /** Un responsable d'agence ne voit ni les agents ni les charges d'une autre agence. */
    public function test_le_tableau_par_agent_respecte_le_perimetre_des_agences(): void
    {
        $responsableB = $this->creerUtilisateurNonAdmin($this->siteB, ['rapports.read']);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 300_000), $this->agentA, 300_000);
        $this->depense($this->agentA, $this->siteA, 40_000, StatutDepense::VALIDE);
        $this->encaisser($this->vente($this->siteB, $this->agentSiteB, 200_000), $this->agentSiteB, 200_000);

        $rapport = $this->props($responsableB, 'rapports.activite')['rapport'];

        $this->assertSame([$this->agentSiteB->id], array_column($rapport['agents']['lignes'], 'agent_id'));
        $this->assertEquals(0.0, $rapport['charges']['resume']['charges_saisies']);
    }

    public function test_les_exports_reprennent_moyens_charges_remise_et_agents(): void
    {
        Excel::fake();
        $this->creerCaisseActive($this->siteA->id, $this->agentA->id);
        $this->encaisser($this->vente($this->siteA, $this->agentA, 300_000), $this->agentA, 300_000);
        $this->depense($this->agentA, $this->siteA, 40_000, StatutDepense::VALIDE);

        $this->actingAs($this->user)->get(route('rapports.activite.export'))->assertOk();
        Excel::assertDownloaded('rapport-activite-2026-09-26.xlsx', function (RapportActiviteExport $export) {
            $feuilles = collect($export->sheets())->keyBy(fn ($f) => $f->title());
            $resume = collect($feuilles['Résumé']->array())->pluck(1, 0);
            $agents = $feuilles['Agents'];
            $ligne = collect($agents->array())->firstWhere(0, 'Moussa Sidibé');

            return $resume['Encaissé Espèces'] == 300_000
                && $resume['Encaissé Orange Money'] == 0
                && $resume['Total encaissé'] == 300_000
                && $resume['Charges saisies (validées + en attente)'] == 40_000
                && $resume['  Statut'] === 'Non remis'
                && $ligne !== null
                && $ligne[array_search('Total encaissé', $agents->headings(), true)] == 300_000
                && $ligne[array_search('Charges saisies', $agents->headings(), true)] == 40_000
                && count($feuilles['Charges']->array()) === 1;
        });

        $this->actingAs($this->agentA)->get(route('ma-situation.export', ['format' => 'pdf']))->assertOk();
    }
}
