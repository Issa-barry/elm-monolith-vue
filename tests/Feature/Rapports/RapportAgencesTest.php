<?php

namespace Tests\Feature\Rapports;

use App\Enums\CategorieDepense;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutDepense;
use App\Enums\StatutFactureVente;
use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rapports\Export\RapportAgencesExport;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Rapport par agence (docs/rapports.md, ADR 0063) : consolidation des moteurs existants — ventes,
 * encaissements par moyen et dettes du rapport d'activité, remise à la trésorerie principale de
 * « Remises des agences » (ADR 0016). Remis = remises REÇUES pendant la période, jamais un montant
 * encaissé ; reste à remettre = calcul du moment, jamais « encaissé − remis ».
 */
class RapportAgencesTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $central;

    private Site $cba;

    private CompteTresorerie $caisseCentrale;

    private CompteTresorerie $caisseCba;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');

        $this->initOrgAndUser(['rapports.read', 'tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.recevoir']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);

        $this->central = $this->user->sites()->firstOrFail();
        $this->central->update(['is_central_tresorerie' => true, 'nom' => 'Matoto']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'agence', 'localisation' => 'Cba']);

        $this->caisseCentrale = $this->caisse($this->central, 5_000_000);
        $this->caisseCba = $this->caisse($this->cba, 1_000_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ────────────────────────────────────────────────────────────────

    private function caisse(Site $site, float $solde): CompteTresorerie
    {
        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => "Caisse {$site->nom}",
        ]);
        $service = app(SoldeOuvertureTresorerieService::class);
        $service->valider($service->enregistrer($this->org->id, $support, [
            'date_situation' => now()->startOfMonth()->toDateString(),
            'montant' => $solde,
        ], $this->user->id), $this->user->id);

        return $support;
    }

    private function envoyer(Site $origine, CompteTresorerie $depuis, Site $destination, CompteTresorerie $vers, float $montant): MouvementFonds
    {
        $service = app(MouvementFondsService::class);
        $mouvement = $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $origine->id,
            'site_destination_id' => $destination->id,
            'compte_tresorerie_origine_id' => $depuis->id,
            'compte_tresorerie_destination_id' => $vers->id,
            'montant' => $montant,
        ], $this->user->id);

        return $service->envoyer($mouvement, $this->user->id);
    }

    private function remettre(float $montant): MouvementFonds
    {
        $remise = $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, $montant);

        return app(MouvementFondsService::class)->recevoir($remise, $this->user->id, $this->caisseCentrale->id);
    }

    private function vente(Site $site, float $montant, string $creeLe = '2026-11-13 09:00:00'): FactureVente
    {
        $this->sequence++;
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'reference' => 'CMD-AG-'.$this->sequence,
            'total_commande' => $montant,
            'statut' => StatutCommandeVente::LIVREE->value,
            'created_by' => $this->user->id,
        ]);
        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'reference' => 'FAC-AG-'.$this->sequence,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => StatutFactureVente::IMPAYEE,
        ]);
        DB::table('factures_ventes')->where('id', $facture->id)->update(['created_at' => $creeLe]);

        return $facture->fresh();
    }

    private function encaisser(FactureVente $facture, float $montant, string $mode = 'especes', ?string $operateur = null, string $date = '2026-11-13'): void
    {
        Auth::login($this->user);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => $date,
            'mode_paiement' => $mode,
            'operateur_mobile_money' => $operateur,
            'reference_paiement' => $operateur ? 'REF-'.$facture->reference : null,
        ]);
        Auth::logout();
    }

    /** @return array<string, mixed> */
    private function props(array $query = [], ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->user)
            ->get(route('rapports.agences').($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function ligne(Site $site, array $query = []): array
    {
        return collect($this->props($query)['rapport']['agences'])->firstWhere('site_id', $site->id);
    }

    // ── Remis à l'agence principale ──────────────────────────────────────────

    public function test_sans_remise_l_agence_est_non_remise_et_doit_tout_son_disponible(): void
    {
        $remise = $this->ligne($this->cba)['remise'];

        $this->assertEquals(0.0, $remise['remis']);
        $this->assertEquals(0.0, $remise['en_transit']);
        $this->assertEquals(1_000_000.0, $remise['reste_a_remettre']);
        $this->assertSame('a_remettre', $remise['statut']);
        $this->assertSame('Non remis', $remise['statut_label']);
    }

    public function test_une_remise_partielle_recue_est_partiellement_remise(): void
    {
        $this->remettre(300_000);

        $remise = $this->ligne($this->cba)['remise'];
        $this->assertEquals(300_000.0, $remise['remis']);
        $this->assertEquals(700_000.0, $remise['reste_a_remettre']);
        $this->assertSame('partiellement_remis', $remise['statut']);
        $this->assertSame('Partiellement remis', $remise['statut_label']);
        $this->assertSame('2026-11-13', $remise['derniere_remise']);
    }

    public function test_une_remise_totale_recue_est_totalement_remise(): void
    {
        $this->remettre(1_000_000);

        $remise = $this->ligne($this->cba)['remise'];
        $this->assertEquals(1_000_000.0, $remise['remis']);
        $this->assertEquals(0.0, $remise['reste_a_remettre']);
        $this->assertSame('remis', $remise['statut']);
        $this->assertSame('Totalement remis', $remise['statut_label']);
    }

    /** Envoyée mais pas encore confirmée par la trésorerie principale : en transit, pas remise. */
    public function test_une_remise_envoyee_non_confirmee_n_est_pas_remise(): void
    {
        $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 400_000);

        $remise = $this->ligne($this->cba)['remise'];
        $this->assertEquals(0.0, $remise['remis']);
        $this->assertEquals(400_000.0, $remise['en_transit']);
        // Le reste baisse dès l'envoi : jamais soustrait une deuxième fois.
        $this->assertEquals(600_000.0, $remise['reste_a_remettre']);
        $this->assertSame('remise_en_cours', $remise['statut']);
    }

    /** Un montant encaissé par l'agence n'est jamais compté comme remis. */
    public function test_un_encaissement_n_est_jamais_compte_comme_remis(): void
    {
        $this->encaisser($this->vente($this->cba, 500_000), 500_000);

        $ligne = $this->ligne($this->cba);
        $this->assertEquals(500_000.0, $ligne['encaisse_montant']);
        $this->assertEquals(0.0, $ligne['remise']['remis']);
        $this->assertNotSame('remis', $ligne['remise']['statut']);
    }

    /** Remis = reçu PENDANT la période : une remise reçue aujourd'hui ne compte pas pour hier. */
    public function test_le_remis_suit_la_periode_et_le_reste_reste_le_calcul_du_moment(): void
    {
        $this->remettre(300_000);

        $hier = $this->ligne($this->cba, ['periode' => 'hier'])['remise'];
        $this->assertEquals(0.0, $hier['remis']);
        $this->assertEquals(700_000.0, $hier['reste_a_remettre']);
        $this->assertSame('a_remettre', $hier['statut']);

        $jour = $this->ligne($this->cba, ['date_debut' => '2026-11-13', 'date_fin' => '2026-11-13'])['remise'];
        $this->assertEquals(300_000.0, $jour['remis']);
    }

    /** Un financement (trésorerie principale → agence) va dans l'autre sens : jamais une remise. */
    public function test_un_financement_vers_l_agence_n_est_pas_une_remise(): void
    {
        $this->envoyer($this->central, $this->caisseCentrale, $this->cba, $this->caisseCba, 200_000);

        $remise = $this->ligne($this->cba)['remise'];
        $this->assertEquals(0.0, $remise['remis']);
        $this->assertEquals(0.0, $remise['en_transit']);
    }

    public function test_la_tresorerie_principale_ne_se_remet_rien(): void
    {
        $remise = $this->ligne($this->central)['remise'];

        $this->assertSame('tresorerie_principale', $remise['statut']);
        $this->assertNull($remise['remis']);
        $this->assertNull($remise['reste_a_remettre']);
    }

    /** Totaux = somme des agences, la trésorerie principale ne comptant ni remis ni reste. */
    public function test_les_totaux_additionnent_les_agences(): void
    {
        $this->remettre(300_000);

        $totaux = $this->props()['rapport']['totaux'];
        $this->assertEquals(300_000.0, $totaux['remis']);
        $this->assertEquals(700_000.0, $totaux['reste_a_remettre']);
        $this->assertFalse($totaux['reste_incomplet']);
        $this->assertSame(['partiellement_remis' => 1], $totaux['par_statut']);
    }

    /** Mêmes chiffres que la vue « Remises des agences » (un seul calcul, ADR 0016). */
    public function test_memes_chiffres_que_la_vue_remises_des_agences(): void
    {
        $this->remettre(250_000);
        $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 100_000);

        $remise = $this->ligne($this->cba, ['periode' => 'ce_mois'])['remise'];
        $vue = collect($this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.remises.index'))
            ->viewData('page')['props']['lignes'])->firstWhere('site_id', $this->cba->id);

        $this->assertEquals($vue['deja_remis'], $remise['remis']);
        $this->assertEquals($vue['en_transit'], $remise['en_transit']);
        $this->assertEquals($vue['reste_a_recevoir'], $remise['reste_a_remettre']);
        $this->assertSame($vue['statut'], $remise['statut']);
    }

    // ── Activité de la période ───────────────────────────────────────────────

    public function test_ventes_encaissements_par_moyen_et_dettes_par_agence(): void
    {
        $espece = $this->vente($this->cba, 600_000);
        $this->encaisser($espece, 600_000);
        $mm = $this->vente($this->cba, 400_000);
        $this->encaisser($mm, 150_000, 'mobile_money', 'orange_money');
        $this->vente($this->central, 900_000);
        // Hors période : ni vente, ni dette, ni encaissement du jour.
        $this->encaisser($this->vente($this->cba, 50_000, '2026-11-10 09:00:00'), 50_000, date: '2026-11-10');

        $props = $this->props();
        $cba = collect($props['rapport']['agences'])->firstWhere('site_id', $this->cba->id);

        $this->assertSame(2, $cba['ventes_nombre']);
        $this->assertEquals(1_000_000.0, $cba['ventes_montant']);
        $this->assertEquals(750_000.0, $cba['encaisse_montant']);
        $this->assertEquals(600_000.0, $cba['par_moyen']['especes']['montant']);
        $this->assertEquals(150_000.0, $cba['par_moyen']['mobile_money:orange_money']['montant']);
        $this->assertEquals(250_000.0, $cba['dettes_montant']);

        $this->assertSame(['especes', 'mobile_money:orange_money'], array_column($props['rapport']['moyens'], 'cle'));
        $this->assertEquals(1_900_000.0, $props['rapport']['totaux']['ventes_montant']);
        $this->assertEquals(1_150_000.0, $props['rapport']['totaux']['dettes_montant']);
    }

    // ── Périmètre, période, export ───────────────────────────────────────────

    public function test_sans_rapports_read_l_ecran_et_l_export_sont_refuses(): void
    {
        $sansDroit = $this->creerUtilisateurNonAdmin($this->cba, ['rapports.read_own']);

        $this->actingAs($sansDroit)->get(route('rapports.agences'))->assertForbidden();
        $this->actingAs($sansDroit)->get(route('rapports.agences.export'))->assertForbidden();
    }

    /** Un responsable d'agence ne voit que ses agences, même en demandant les autres. */
    public function test_un_responsable_ne_voit_que_ses_agences(): void
    {
        $responsable = $this->creerUtilisateurNonAdmin($this->cba, ['rapports.read']);
        $autreOrg = Organization::factory()->create();
        $etranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'agence', 'localisation' => 'Ailleurs']);

        $props = $this->props(['site_ids' => [$this->central->id, $etranger->id]], $responsable);
        $this->assertSame([], $props['rapport']['agences']);

        $props = $this->props([], $responsable);
        $this->assertSame([$this->cba->id], array_column($props['rapport']['agences'], 'site_id'));
        $this->assertFalse($props['peut_voir_remises']);
    }

    public function test_le_filtre_agence_restreint_les_lignes(): void
    {
        $props = $this->props(['site_ids' => [$this->cba->id]]);

        $this->assertSame([$this->cba->id], array_column($props['rapport']['agences'], 'site_id'));
        $this->assertSame([$this->cba->id], $props['filters']['site_ids']);
        $this->assertTrue($props['peut_voir_remises']);
    }

    /** Une date précise = une journée : date de début et de fin identiques, rendue en « Date précise ». */
    public function test_une_date_precise_couvre_une_seule_journee(): void
    {
        $this->vente($this->cba, 100_000, '2026-11-12 23:30:00');
        $this->vente($this->cba, 200_000, '2026-11-13 00:10:00');

        $props = $this->props(['date_debut' => '2026-11-12', 'date_fin' => '2026-11-12']);
        $cba = collect($props['rapport']['agences'])->firstWhere('site_id', $this->cba->id);

        $this->assertEquals(100_000.0, $cba['ventes_montant']);
        $this->assertSame('date_precise', $props['filters']['periode']);
        $this->assertSame('2026-11-12', $props['filters']['date_debut']);
        $this->assertSame('2026-11-12', $props['filters']['date_fin']);
        $this->assertSame('date_precise', $props['periode']['cle']);
        $this->assertSame('12/11/2026', $props['periode']['libelle']);
        $this->assertContains('date_precise', array_column($props['periode']['options'], 'value'));
        $this->assertContains('mois_precedent', array_column($props['periode']['options'], 'value'));
    }

    public function test_la_periode_par_defaut_est_aujourd_hui_et_une_periode_invalide_est_refusee(): void
    {
        $this->assertSame('aujourd_hui', $this->props()['filters']['periode']);

        $this->actingAs($this->user)
            ->get(route('rapports.agences', ['date_debut' => '2026-11-13', 'date_fin' => '2026-11-01']))
            ->assertSessionHasErrors('date_fin');
        $this->actingAs($this->user)
            ->get(route('rapports.agences', ['periode' => 'date_precise']))
            ->assertSessionHasErrors('periode');
    }

    public function test_l_export_excel_reprend_le_tableau(): void
    {
        Excel::fake();
        $this->remettre(300_000);

        $this->actingAs($this->user)
            ->get(route('rapports.agences.export', ['periode' => 'ce_mois']))
            ->assertOk();

        Excel::assertDownloaded('rapport-agences-2026-11-01-au-2026-11-30.xlsx', function (RapportAgencesExport $export) {
            $feuilles = $export->sheets();
            $agences = $feuilles[1]->array();
            $cba = collect($agences)->firstWhere(0, 'Cba');

            return $feuilles[1]->title() === 'Agences'
                && in_array('Remis à la trésorerie principale (sur la période)', $feuilles[1]->headings(), true)
                && in_array('Reste à remettre (à ce jour)', $feuilles[1]->headings(), true)
                && $cba !== null
                && in_array('Partiellement remis', $cba, true)
                && in_array(300_000.0, $cba, true);
        });
    }

    // ── Remises multiples ────────────────────────────────────────────────────

    /**
     * Plusieurs remises pour la même agence : reçues hier et aujourd'hui, en transit, contestée,
     * brouillon et annulée. Seul « Remis » suit la période ; en transit et reste sont à ce jour ;
     * attendu (reste + en transit + reçu sur le mois) reste égal aux fonds de départ, sans double comptage.
     */
    public function test_remises_multiples_partielles_en_transit_et_confirmees(): void
    {
        $service = app(MouvementFondsService::class);

        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->remettre(150_000);
        Carbon::setTestNow('2026-11-13 12:00:00');

        $this->remettre(200_000);
        $this->remettre(300_000);
        $enTransit = $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 100_000);
        $service->contester($this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 50_000), $this->user->id, 'Montant à vérifier');
        $brouillon = fn (float $montant) => $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->cba->id,
            'site_destination_id' => $this->central->id,
            'compte_tresorerie_origine_id' => $this->caisseCba->id,
            'compte_tresorerie_destination_id' => $this->caisseCentrale->id,
            'montant' => $montant,
        ], $this->user->id);
        $brouillon(80_000);
        $service->annuler($brouillon(70_000), $this->user->id, 'Saisie en double');

        $jour = $this->ligne($this->cba)['remise'];
        $this->assertEquals(500_000.0, $jour['remis']);
        $this->assertEquals(150_000.0, $jour['en_transit']);
        $this->assertEquals(200_000.0, $jour['reste_a_remettre']);
        $this->assertSame('remise_en_cours', $jour['statut']);
        $this->assertSame('2026-11-13', $jour['derniere_remise']);

        $hier = $this->ligne($this->cba, ['periode' => 'hier'])['remise'];
        $this->assertEquals(150_000.0, $hier['remis']);
        $this->assertEquals(150_000.0, $hier['en_transit']);
        $this->assertEquals(200_000.0, $hier['reste_a_remettre']);

        $this->assertEquals(150_000.0, $this->ligne($this->cba, ['date_debut' => '2026-11-12', 'date_fin' => '2026-11-12'])['remise']['remis']);

        $mois = $this->ligne($this->cba, ['periode' => 'ce_mois'])['remise'];
        $this->assertEquals(650_000.0, $mois['remis']);
        $this->assertEquals(1_000_000.0, $mois['reste_a_remettre'] + $mois['en_transit'] + $mois['remis']);

        // La remise en transit est confirmée : elle passe de « En transit » à « Remis », le reste ne bouge pas.
        $service->recevoir($enTransit, $this->user->id, $this->caisseCentrale->id);
        $apres = $this->ligne($this->cba)['remise'];
        $this->assertEquals(600_000.0, $apres['remis']);
        $this->assertEquals(50_000.0, $apres['en_transit']);
        $this->assertEquals(200_000.0, $apres['reste_a_remettre']);
        $this->assertSame('remise_en_cours', $apres['statut']);
    }

    // ── Charges saisies (module Dépenses) ────────────────────────────────────

    private function depense(Site $site, float $montant, StatutDepense $statut, CategorieDepense $categorie = CategorieDepense::INTERNE, string $date = '2026-11-13', ?Organization $org = null): Depense
    {
        $org ??= $this->org;

        return Depense::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => DepenseType::factory()->create(['organization_id' => $org->id, 'categorie' => $categorie->value])->id,
            'montant' => $montant,
            'date_depense' => $date,
            'statut' => $statut->value,
        ]);
    }

    /**
     * Payées = validées (la validation décaisse), en attente = soumises, avances sur tiers à part ;
     * brouillons, rejetées, annulées, supprimées, hors période ou d'une autre agence exclues.
     */
    public function test_charges_payees_en_attente_et_avances_par_agence(): void
    {
        $this->depense($this->cba, 120_000, StatutDepense::VALIDE);
        $this->depense($this->cba, 80_000, StatutDepense::VALIDE, CategorieDepense::LIVREUR);
        $this->depense($this->cba, 50_000, StatutDepense::SOUMIS);
        $this->depense($this->cba, 10_000, StatutDepense::BROUILLON);
        $this->depense($this->cba, 20_000, StatutDepense::REJETE);
        $this->depense($this->cba, 30_000, StatutDepense::ANNULE);
        $this->depense($this->cba, 40_000, StatutDepense::VALIDE)->delete();
        $this->depense($this->cba, 60_000, StatutDepense::VALIDE, date: '2026-11-12');
        $this->depense($this->central, 300_000, StatutDepense::VALIDE);

        $props = $this->props();
        $cba = collect($props['rapport']['agences'])->firstWhere('site_id', $this->cba->id);

        $this->assertEquals(200_000.0, $cba['charges_payees']);
        $this->assertSame(2, $cba['charges_payees_nombre']);
        $this->assertEquals(80_000.0, $cba['charges_avances_tiers']);
        $this->assertEquals(50_000.0, $cba['charges_en_attente']);
        $this->assertSame(1, $cba['charges_en_attente_nombre']);

        $this->assertEquals(500_000.0, $props['rapport']['totaux']['charges_payees']);
        $this->assertEquals(50_000.0, $props['rapport']['totaux']['charges_en_attente']);

        // Date précise de la veille : seule la charge du 12/11.
        $hier = collect($this->props(['date_debut' => '2026-11-12', 'date_fin' => '2026-11-12'])['rapport']['agences'])->firstWhere('site_id', $this->cba->id);
        $this->assertEquals(60_000.0, $hier['charges_payees']);
    }

    /** Les charges ne sont jamais déduites du remis : seules les remises reçues comptent. */
    public function test_les_charges_ne_changent_pas_le_remis(): void
    {
        $this->remettre(300_000);
        $this->depense($this->cba, 100_000, StatutDepense::VALIDE);

        $remise = $this->ligne($this->cba)['remise'];
        $this->assertEquals(300_000.0, $remise['remis']);
        $this->assertSame('partiellement_remis', $remise['statut']);
    }

    public function test_les_charges_d_une_autre_organisation_sont_ignorees(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->depense($this->cba, 999_000, StatutDepense::VALIDE, org: $autreOrg);

        $this->assertEquals(0.0, $this->ligne($this->cba)['charges_payees']);
    }

    // ── Droits d'accès ───────────────────────────────────────────────────────

    /** Le lien « Remises » n'est proposé que si sa route est ouverte : module Comptabilité actif. */
    public function test_sans_module_comptabilite_pas_de_lien_vers_les_remises(): void
    {
        Feature::for($this->org)->deactivate(ModuleFeature::COMPTABILITE);

        $this->assertFalse($this->props()['peut_voir_remises']);
    }

    /** Un responsable d'agence avec tresorerie.read : le lien proposé mène bien au détail (jamais un 403). */
    public function test_le_lien_remises_d_un_responsable_ouvre_le_detail_de_son_agence(): void
    {
        $responsable = $this->creerUtilisateurNonAdmin($this->cba, ['rapports.read', 'tresorerie.read']);

        $props = $this->props([], $responsable);
        $this->assertTrue($props['peut_voir_remises']);
        $this->assertSame([$this->cba->id], array_column($props['rapport']['agences'], 'site_id'));

        $this->actingAs($responsable)->get(route('comptabilite.tresorerie.remises.show', $this->cba))->assertOk();
    }

    public function test_l_export_d_un_responsable_ne_contient_que_ses_agences(): void
    {
        Excel::fake();
        $responsable = $this->creerUtilisateurNonAdmin($this->cba, ['rapports.read']);

        $this->actingAs($responsable)
            ->get(route('rapports.agences.export', ['site_ids' => [$this->central->id, $this->cba->id]]))
            ->assertOk();

        Excel::assertDownloaded('rapport-agences-2026-11-13.xlsx', fn (RapportAgencesExport $export) => array_column($export->sheets()[1]->array(), 0) === ['Cba']);
    }

    /** Isolation des organisations : un administrateur d'une autre organisation ne voit aucune agence d'ici. */
    public function test_une_autre_organisation_ne_voit_pas_ces_agences(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'agence', 'localisation' => 'Ailleurs']);
        $etranger = $this->makeUserWithPermissions($autreOrg, ['rapports.read']);
        $etranger->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->props(['site_ids' => [$this->cba->id]], $etranger);
        $this->assertSame([], $props['rapport']['agences']);
        $this->assertSame([$autreSite->id], array_column($this->props([], $etranger)['rapport']['agences'], 'site_id'));
    }

    // ── Export : cohérence avec l'écran ──────────────────────────────────────

    public function test_l_export_reprend_exactement_les_lignes_et_les_totaux_de_l_ecran(): void
    {
        Excel::fake();
        $this->encaisser($this->vente($this->cba, 600_000), 600_000);
        $this->encaisser($this->vente($this->cba, 400_000), 150_000, 'mobile_money', 'orange_money');
        $this->encaisser($this->vente($this->central, 900_000), 900_000);
        $this->remettre(300_000);
        $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 100_000);

        $rapport = $this->props()['rapport'];
        $this->actingAs($this->user)->get(route('rapports.agences.export'))->assertOk();

        Excel::assertDownloaded('rapport-agences-2026-11-13.xlsx', function (RapportAgencesExport $export) use ($rapport) {
            [$resume, $agences] = $export->sheets();
            $entetes = $agences->headings();
            $col = fn (string $titre) => array_search($titre, $entetes, true);
            $lignes = collect($agences->array());
            $valeur = fn (string $libelle) => collect($resume->array())->firstWhere(0, $libelle)[1] ?? null;
            $totaux = $rapport['totaux'];

            // Une ligne par agence de l'écran, dans le même ordre, avec les mêmes montants.
            $this->assertSame(array_column($rapport['agences'], 'site_nom'), $lignes->pluck(0)->all());
            foreach ($rapport['agences'] as $i => $a) {
                $l = $lignes[$i];
                $this->assertEquals($a['ventes_montant'], $l[$col('Ventes')]);
                $this->assertEquals($a['encaisse_montant'], $l[$col('Encaissé')]);
                $this->assertEquals($a['par_moyen']['especes']['montant'] ?? 0.0, $l[$col('Espèces')]);
                $this->assertEquals($a['dettes_montant'], $l[$col('Dettes clients')]);
                $this->assertEquals($a['remise']['remis'] ?? '', $l[$col('Remis à la trésorerie principale (sur la période)')]);
                $this->assertEquals($a['remise']['reste_a_remettre'] ?? '', $l[$col('Reste à remettre (à ce jour)')]);
                $this->assertSame($a['remise']['statut_label'], $l[$col('Statut de remise')]);
            }

            // Résumé = totaux de l'écran = somme des colonnes, en nombres (jamais du texte).
            $this->assertEquals($totaux['encaisse_montant'], $lignes->sum(fn ($l) => $l[$col('Encaissé')]));
            $this->assertEquals($totaux['ventes_montant'], $valeur('Montant facturé'));
            $this->assertEquals($totaux['encaisse_montant'], $valeur('Montant encaissé'));
            $this->assertEquals($totaux['remis'], $valeur('Remis à la trésorerie principale (reçu sur la période)'));
            $this->assertEquals($totaux['en_transit'], $valeur('En transit vers la trésorerie principale (à ce jour)'));
            $this->assertIsFloat($valeur('Reste à remettre (à ce jour)'));
            $this->assertEquals($totaux['reste_a_remettre'], $valeur('Reste à remettre (à ce jour)'));
            $this->assertEquals(1_650_000.0, $totaux['encaisse_montant']);
            $this->assertEquals(300_000.0, $totaux['remis']);
            $this->assertEquals(100_000.0, $totaux['en_transit']);

            return true;
        });
    }
}
