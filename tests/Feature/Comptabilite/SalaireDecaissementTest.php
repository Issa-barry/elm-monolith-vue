<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaie;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\Site;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\DecaissementFicheResolver;
use App\Services\Tresorerie\DecaissementSupportResolver;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use App\Services\Tresorerie\SupportTresorerieSuppressionService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\HasPaieSalaries;
use Tests\TestCase;

/**
 * Payer un salaire = décaissement réel (ADR 0049), comme une commission (ADR 0009) : l'argent sort
 * d'un support de l'agence du salarié (site central de trésorerie pour un salarié sans agence), caisse
 * dédiée du payeur en espèces. Solde insuffisant = refus serveur, sans aucun effet. Mêmes règles sur
 * les trois chemins : ligne de paie, paiement global d'un salarié, ancienne route Paie RH.
 */
class SalaireDecaissementTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasPaieSalaries, RefreshDatabase;

    private Site $agence;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        $this->agence = $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
    }

    private function ligneValidee(?Site $site, float $salaire = 1_000_000): PaieLigne
    {
        $employe = $this->creerSalarie($site, $salaire);

        return $this->ligneDe($this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH), $employe);
    }

    private function payer(PaieLigne $ligne, array $donnees)
    {
        return $this->actingAs($this->user)->post(route('comptabilite.salaires.payer', $ligne), array_merge([
            'date_paiement' => now()->toDateString(),
        ], $donnees));
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh(), now());
    }

    // ── Débit du support réel ─────────────────────────────────────────────────

    public function test_especes_debite_la_caisse_dediee_du_payeur(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, ['montant' => 200_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertSame(300_000.0, $this->solde($caisse));
        $paiement = PaiePaiement::where('paie_ligne_id', $ligne->id)->sole();
        $this->assertSame($caisse->id, $paiement->compte_tresorerie_id);
        $this->assertSame($this->agence->id, $paiement->site_id);
        $this->assertSame($this->user->id, $paiement->created_by);
    }

    public function test_mobile_money_debite_le_compte_reel_et_garde_la_reference(): void
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 1_000_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, [
            'montant' => 300_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => 'OM123456789',
        ])->assertSessionHasNoErrors();

        $this->assertSame(700_000.0, $this->solde($orange));
        $this->assertDatabaseHas('paie_paiements', [
            'paie_ligne_id' => $ligne->id,
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => 'OM123456789',
            'moyen_paiement_detail' => 'orange_money',
        ]);
    }

    public function test_virement_debite_le_compte_bancaire_sans_reference(): void
    {
        $banque = $this->creerSupportAgence($this->agence->id, 'banque', '521000');
        $this->alimenterCaisse($banque, 1_000_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, [
            'montant' => 1_000_000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->solde($banque));
        $this->assertEquals(0, (float) $ligne->fresh()->reste_a_payer);
    }

    public function test_un_net_avec_decimales_se_paie_entierement(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 1_000_000);
        $ligne = $this->ligneValidee($this->agence, 750_012.9);

        $this->payer($ligne, ['montant' => 750_012.9, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $ligne->fresh()->reste_a_payer);
    }

    public function test_annuler_un_paiement_rend_l_argent_au_support(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $ligne = $this->ligneValidee($this->agence);
        $this->payer($ligne, ['montant' => 200_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $paiement = PaiePaiement::where('paie_ligne_id', $ligne->id)->sole();
        $this->actingAs($this->user)->delete(route('paie-paiements.destroy', $paiement))->assertRedirect();

        $this->assertSame(500_000.0, $this->solde($caisse));
    }

    // ── Refus sans aucun effet ────────────────────────────────────────────────

    public function test_solde_insuffisant_refuse_sans_aucun_effet(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 100_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, ['montant' => 100_001, 'mode_paiement' => 'especes'])->assertSessionHasErrors('montant');

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
        $this->assertSame(100_000.0, $this->solde($caisse));
        $this->assertEquals(0, (float) $ligne->fresh()->deja_paye);
    }

    public function test_especes_sans_caisse_du_payeur_refuse(): void
    {
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['mode_paiement' => DecaissementSupportResolver::MESSAGE_SANS_CAISSE]);

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_hors_especes_le_compte_est_obligatoire(): void
    {
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, ['montant' => 100_000, 'mode_paiement' => 'virement'])
            ->assertSessionHasErrors('compte_tresorerie_id');
    }

    public function test_reference_obligatoire_en_mobile_money(): void
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 1_000_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, [
            'montant' => 100_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
        ])->assertSessionHasErrors('reference_paiement');

        $this->assertSame(1_000_000.0, $this->solde($orange));
    }

    public function test_un_support_d_une_autre_agence_est_refuse(): void
    {
        $autreAgence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'depot']);
        $banque = $this->creerSupportAgence($autreAgence->id, 'banque', '521000');
        $this->alimenterCaisse($banque, 1_000_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, [
            'montant' => 100_000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
        ])->assertSessionHasErrors(['compte_tresorerie_id' => DecaissementSupportResolver::MESSAGE_MOYEN_INDISPONIBLE]);

        $this->assertSame(1_000_000.0, $this->solde($banque));
    }

    public function test_un_support_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreAgence = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Agence externe', 'type' => 'depot']);
        $banque = $this->creerSupportAgence($autreAgence->id, 'banque', '521000');
        $ligne = $this->ligneValidee($this->agence);

        $this->payer($ligne, [
            'montant' => 100_000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    // ── Salarié sans agence ───────────────────────────────────────────────────

    public function test_salarie_sans_agence_paye_depuis_le_site_central(): void
    {
        $central = Site::create(['organization_id' => $this->org->id, 'nom' => 'Trésorerie', 'type' => 'depot']);
        app(SiteCentralTresorerieResolver::class)->designer($central);
        $this->user->sites()->attach($central->id, ['role' => 'employe', 'is_default' => false]);
        $caisse = $this->equiperPayeurEspeces($this->user, $central->id, 500_000);
        $ligne = $this->ligneValidee(null);

        $this->payer($ligne, ['montant' => 200_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertSame(300_000.0, $this->solde($caisse));
        $this->assertSame($central->id, PaiePaiement::where('paie_ligne_id', $ligne->id)->sole()->site_id);
    }

    public function test_salarie_sans_agence_et_sans_site_central_impossible_a_payer(): void
    {
        $this->assertNull(app(SiteCentralTresorerieResolver::class)->centralOuNull($this->org->id));
        $ligne = $this->ligneValidee(null);

        $this->payer($ligne, ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['compte_tresorerie_id' => DecaissementFicheResolver::MESSAGE_SANS_SITE_CENTRAL]);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->where('lignes.0.tresorerie.message', DecaissementFicheResolver::MESSAGE_SANS_SITE_CENTRAL));
    }

    // ── Paiement global et ancienne route : mêmes règles ──────────────────────

    public function test_paiement_global_controle_le_solde_sur_le_total_et_debite_une_seule_fois(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 1_500_000);
        $employe = $this->creerSalarie($this->agence);
        $janvier = $this->ligneDe($this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH), $employe);
        $fevrier = $this->ligneDe($this->creerPeriodeCalculee(2, 2025, StatutPeriodePaie::VALIDE_RH), $employe);
        $url = "/backoffice/comptabilite/salaires/employes/{$employe->id}/paiements";

        // Chaque mois tiendrait dans la caisse (1 000 000), pas le total.
        $this->actingAs($this->user)->post($url, ['montant' => 1_600_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors('montant');
        $this->assertSame(0, PaiePaiement::count());

        $this->actingAs($this->user)->post($url, ['montant' => 1_500_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->solde($caisse));
        $this->assertEquals(1_000_000, (float) $janvier->fresh()->deja_paye);
        $this->assertEquals(500_000, (float) $fevrier->fresh()->deja_paye);
        $this->assertSame(2, PaiePaiement::where('compte_tresorerie_id', $caisse->id)->count());
    }

    public function test_l_ancienne_route_paie_rh_debite_aussi_le_support(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->actingAs($this->user)->post(route('paie-paiements.store', $ligne), [
            'montant' => 100_000,
            'date_paiement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors();

        $this->assertSame(400_000.0, $this->solde($caisse));
    }

    public function test_l_ancienne_route_paie_rh_refuse_sans_caisse(): void
    {
        $ligne = $this->ligneValidee($this->agence);

        $this->actingAs($this->user)->post(route('paie-paiements.store', $ligne), [
            'montant' => 100_000,
            'date_paiement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ])->assertSessionHasErrors('mode_paiement');

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    // ── Données du dialogue ───────────────────────────────────────────────────

    public function test_l_ecran_fournit_les_moyens_de_l_agence_et_la_caisse_du_payeur(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 700_000);
        $this->ligneValidee($this->agence);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->where('lignes.0.peut_payer', true)
                ->where('lignes.0.tresorerie.site_id', $this->agence->id)
                ->where('lignes.0.tresorerie.especes_disponibles', true)
                ->where('lignes.0.tresorerie.solde_especes', fn ($v) => (float) $v === 500_000.0)
                ->where('lignes.0.tresorerie.moyens.0.compte_tresorerie_id', $orange->id)
                ->where('lignes.0.tresorerie.moyens.0.solde_disponible', fn ($v) => (float) $v === 700_000.0)
                ->where('lignes.0.tresorerie.message', null));
    }

    public function test_l_ecran_ne_fournit_rien_pour_une_ligne_impayable(): void
    {
        $autreAgence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'depot']);
        $this->ligneValidee($autreAgence);
        $comptable = $this->creerComptable(
            [$this->agence],
            ['comptabilite.read', 'comptabilite.payer', 'sites.lecture_toutes_agences'],
        );

        $this->actingAs($comptable)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->where('lignes.0.peut_payer', false)
                ->where('lignes.0.tresorerie', null));
    }

    public function test_une_periode_non_validee_ne_fournit_aucune_donnee_de_paiement(): void
    {
        $this->creerSalarie($this->agence);
        $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::CALCULE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page->where('lignes.0.tresorerie', null));
    }

    public function test_la_fiche_du_salarie_fournit_les_moyens_de_son_agence(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $ligne = $this->ligneValidee($this->agence);

        $this->actingAs($this->user)
            ->get("/backoffice/comptabilite/salaires/employes/{$ligne->employe_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_payer', true)
                ->where('tresorerie.site_id', $this->agence->id)
                ->where('tresorerie.solde_especes', fn ($v) => (float) $v === 500_000.0));
    }

    // ── Support référencé ─────────────────────────────────────────────────────

    public function test_un_brouillon_reference_par_un_paiement_de_salaire_n_est_pas_supprimable(): void
    {
        $brouillon = app(CaisseAgentService::class)->creer($this->org->id, $this->agence->id, $this->user->id);
        $ligne = $this->ligneValidee($this->agence);
        PaiePaiement::create([
            'paie_ligne_id' => $ligne->id,
            'compte_tresorerie_id' => $brouillon->id,
            'montant' => 100_000,
            'date_paiement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->assertErreurValidationSur('statut', fn () => app(SupportTresorerieSuppressionService::class)->supprimer($brouillon, $this->user));

        $this->assertDatabaseHas('compta_supports_tresorerie', ['id' => $brouillon->id]);
    }
}
