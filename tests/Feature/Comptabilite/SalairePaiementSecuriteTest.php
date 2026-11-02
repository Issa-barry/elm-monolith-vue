<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaie;
use App\Models\CompteMapping;
use App\Models\Organization;
use App\Models\PaiePaiement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\HasPaieSalaries;
use Tests\TestCase;

/**
 * Paiement des salaires (PaiementSalaireService) : reste à payer relu sous verrou, rien
 * d'enregistré si la comptabilisation échoue, agences d'écriture et organisation contrôlées sur les
 * trois chemins (ligne, salarié, ancienne route Paie RH).
 */
class SalairePaiementSecuriteTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasPaieSalaries, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        $this->agenceA = $this->user->sites()->first();
        $this->agenceB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
    }

    private function payerLigne(string $ligneId, float $montant)
    {
        return $this->post(route('comptabilite.salaires.payer', $ligneId), [
            'montant' => $montant,
            'date_paiement' => '2025-01-31',
            'mode_paiement' => 'especes',
        ]);
    }

    // ── Reste à payer et comptabilisation ─────────────────────────────────────

    public function test_le_reste_est_relu_depuis_les_paiements_et_non_depuis_la_colonne(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $ligne = $this->ligneDe($periode, $employe);

        // Paiement enregistré par une requête concurrente : la colonne reste_a_payer est encore à
        // 1 000 000 quand la seconde requête arrive.
        $ligne->paiements()->create(['montant' => 800_000, 'date_paiement' => '2025-01-31', 'mode_paiement' => 'especes']);
        $this->assertEquals(1_000_000, (float) $ligne->fresh()->reste_a_payer);

        $this->actingAs($this->user)->payerLigne($ligne->id, 500_000)->assertSessionHasErrors('montant');

        $this->assertSame(1, PaiePaiement::where('paie_ligne_id', $ligne->id)->count());
    }

    public function test_rien_n_est_enregistre_si_la_comptabilisation_echoue(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $ligne = $this->ligneDe($periode, $employe);
        $this->equiperPayeurEspeces($this->user, $this->agenceA->id);
        CompteMapping::where('organization_id', $this->org->id)->where('evenement', 'paiement_salaire')->delete();

        $this->actingAs($this->user)->payerLigne($ligne->id, 400_000)->assertSessionHasErrors('comptabilisation');

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
        $this->assertEquals(0, (float) $ligne->fresh()->deja_paye);
    }

    public function test_paiement_global_annule_entierement_si_la_comptabilisation_echoue(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $janvier = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $fevrier = $this->creerPeriodeCalculee(2, 2025, StatutPeriodePaie::VALIDE_RH);
        $this->equiperPayeurEspeces($this->user, $this->agenceA->id);
        CompteMapping::where('organization_id', $this->org->id)->where('evenement', 'paiement_salaire')->delete();

        $this->actingAs($this->user)
            ->post("/backoffice/comptabilite/salaires/employes/{$employe->id}/paiements", [
                'montant' => 1_500_000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('comptabilisation');

        $this->assertSame(0, PaiePaiement::whereIn('paie_ligne_id', [
            $this->ligneDe($janvier, $employe)->id,
            $this->ligneDe($fevrier, $employe)->id,
        ])->count());
    }

    public function test_paiement_global_refuse_s_il_touche_un_mois_non_valide(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $this->creerPeriodeCalculee(2, 2025, StatutPeriodePaie::BROUILLON);

        $this->actingAs($this->user)
            ->post("/backoffice/comptabilite/salaires/employes/{$employe->id}/paiements", [
                'montant' => 1_500_000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');

        $this->assertSame(0, PaiePaiement::count());
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function test_l_ancienne_route_paie_rh_exige_comptabilite_payer(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $ligne = $this->ligneDe($this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH), $employe);
        $rhSeul = $this->makeUserWithPermissions($this->org, ['rh-paie.read', 'rh-paie.pay']);
        $rhSeul->sites()->attach($this->agenceA->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($rhSeul)
            ->post(route('paie-paiements.store', $ligne), [
                'montant' => 100_000,
                'date_paiement' => '2025-01-31',
                'mode_paiement' => 'especes',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    // ── Agences ───────────────────────────────────────────────────────────────

    public function test_un_comptable_ne_paie_que_les_salaries_de_ses_agences(): void
    {
        $salarieA = $this->creerSalarie($this->agenceA);
        $salarieB = $this->creerSalarie($this->agenceB);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $comptable = $this->creerComptable([$this->agenceA]);
        $this->equiperPayeurEspeces($comptable, $this->agenceA->id);

        $this->actingAs($comptable)->payerLigne($this->ligneDe($periode, $salarieB)->id, 100_000)->assertForbidden();
        $this->actingAs($comptable)
            ->post("/backoffice/comptabilite/salaires/employes/{$salarieB->id}/paiements", ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertForbidden();
        $this->actingAs($comptable)
            ->post(route('paie-paiements.store', $this->ligneDe($periode, $salarieB)), [
                'montant' => 100_000, 'date_paiement' => '2025-01-31', 'mode_paiement' => 'especes',
            ])
            ->assertForbidden();

        $this->actingAs($comptable)->payerLigne($this->ligneDe($periode, $salarieA)->id, 100_000)->assertSessionHasNoErrors();

        $this->assertSame(1, PaiePaiement::count());
    }

    public function test_un_comptable_ne_voit_ni_n_exporte_que_ses_agences(): void
    {
        $salarieA = $this->creerSalarie($this->agenceA);
        $salarieB = $this->creerSalarie($this->agenceB);
        $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $comptable = $this->creerComptable([$this->agenceA]);

        $this->actingAs($comptable)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->has('lignes', 1)
                ->where('lignes.0.employe_id', $salarieA->id)
                ->where('lignes.0.peut_payer', true));

        $csv = $this->actingAs($comptable)
            ->get('/backoffice/comptabilite/salaires/export/excel?mois=1&annee=2025')
            ->streamedContent();
        $this->assertStringContainsString($salarieA->nom_complet, $csv);
        $this->assertStringNotContainsString($salarieB->nom_complet, $csv);

        $this->actingAs($comptable)
            ->get("/backoffice/comptabilite/salaires/employes/{$salarieB->id}")
            ->assertForbidden();
    }

    public function test_la_vision_360_permet_de_consulter_mais_pas_de_payer_une_autre_agence(): void
    {
        $salarieB = $this->creerSalarie($this->agenceB);
        $periode = $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $comptable = $this->creerComptable(
            [$this->agenceA],
            ['comptabilite.read', 'comptabilite.payer', User::PERMISSION_LECTURE_TOUTES_AGENCES],
        );

        $this->actingAs($comptable)
            ->get('/backoffice/comptabilite/salaires?mois=1&annee=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->has('lignes', 1)
                ->where('lignes.0.peut_payer', false));

        $this->actingAs($comptable)
            ->get("/backoffice/comptabilite/salaires/employes/{$salarieB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_payer', false));

        $this->actingAs($comptable)->payerLigne($this->ligneDe($periode, $salarieB)->id, 100_000)->assertForbidden();
    }

    // ── Organisation ──────────────────────────────────────────────────────────

    public function test_un_super_admin_ne_paie_pas_une_ligne_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $employe = $this->creerSalarie(null, org: $autreOrg);
        $ligne = $this->ligneDe($this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH, $autreOrg), $employe);

        $this->actingAs($this->creerSuperAdmin($this->org))
            ->post(route('paie-paiements.store', $ligne), [
                'montant' => 100_000,
                'date_paiement' => '2025-01-31',
                'mode_paiement' => 'especes',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_la_fiche_salarie_ne_propose_que_le_reste_des_mois_valides(): void
    {
        $employe = $this->creerSalarie($this->agenceA);
        $this->creerPeriodeCalculee(1, 2025, StatutPeriodePaie::VALIDE_RH);
        $this->creerPeriodeCalculee(2, 2025, StatutPeriodePaie::BROUILLON);

        $this->actingAs($this->user)
            ->get("/backoffice/comptabilite/salaires/employes/{$employe->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('commission_summary.reste_a_payer', fn ($v) => (float) $v === 2_000_000.0)
                ->where('reste_payable', fn ($v) => (float) $v === 1_000_000.0)
                ->where('can_payer', true));
    }
}
