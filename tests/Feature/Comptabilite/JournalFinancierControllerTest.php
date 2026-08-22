<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutSoldeOuverture;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\SoldeOuvertureTresorerie;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Journal financier — reconstruit comme vue de lecture pure sur le grand
 * livre (compta_ecritures/compta_pieces) après suppression de l'ancien
 * JournalTresorerie. Ces tests verrouillent : lecture exclusive du grand
 * livre, isolation par organisation et par site, KPI corrects.
 */
class JournalFinancierControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read']);
    }

    private function postSoldeOuverture(Organization $org, Site $site, float $montant): void
    {
        $compteCaisse = CompteComptable::where('organization_id', $org->id)->where('numero', '571000')->firstOrFail();
        $support = CompteTresorerie::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compteCaisse->id,
            'type' => 'caisse',
            'libelle' => 'Caisse Test',
        ]);

        $solde = SoldeOuvertureTresorerie::create([
            'organization_id' => $org->id,
            'compte_tresorerie_id' => $support->id,
            'date_situation' => '2026-06-01',
            'montant' => $montant,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        app(SoldeOuvertureTresorerieService::class)->valider($solde, $this->user->id);
    }

    public function test_index_retourne_200_et_les_kpis_attendus(): void
    {
        $site = $this->user->sites()->first();
        $this->postSoldeOuverture($this->org, $site, 500_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.journal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Journal')
                ->has('lignes.data', 1)
                ->where('kpis.total_entrees', 500_000)
                ->where('kpis.total_sorties', 0)
                ->where('kpis.solde', 500_000)
            );
    }

    public function test_index_isole_par_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'X', 'type' => 'depot', 'localisation' => 'Y']);
        $this->postSoldeOuverture($autreOrg, $autreSite, 1_000_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.journal'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lignes.data', 0)
                ->where('kpis.total_entrees', 0)
            );
    }

    public function test_filtre_evenement_restreint_les_lignes(): void
    {
        $site = $this->user->sites()->first();
        $this->postSoldeOuverture($this->org, $site, 300_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.journal', ['evenement' => EvenementComptable::PAIEMENT_SALAIRE->value]))
            ->assertInertia(fn (Assert $page) => $page->has('lignes.data', 0));

        $this->actingAs($this->user)
            ->get(route('comptabilite.journal', ['evenement' => EvenementComptable::SOLDE_OUVERTURE_TRESORERIE->value]))
            ->assertInertia(fn (Assert $page) => $page->has('lignes.data', 1));
    }

    public function test_les_lignes_hors_compte_de_tresorerie_napparaissent_jamais(): void
    {
        // Une écriture sur un compte de charge (622100, jamais un compte de trésorerie)
        // ne doit jamais apparaître dans le Journal financier — même mécanisme que
        // TresorerieDisponibiliteService (comptes de trésorerie uniquement).
        $site = $this->user->sites()->first();
        $compteCharge = CompteComptable::where('organization_id', $this->org->id)->where('numero', '622100')->firstOrFail();
        $compteDette = CompteComptable::where('organization_id', $this->org->id)->where('numero', '467110')->firstOrFail();

        $fiche = PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => PaiementPeriode::create([
                'organization_id' => $this->org->id,
                'reference' => 'PER-TEST',
                'type' => 'proprietaire',
                'date_debut' => '2026-06-01',
                'date_fin' => '2026-06-30',
                'statut' => 'calculee',
            ])->id,
            'reference' => 'FIC-TEST',
            'beneficiaire_type' => 'proprietaire',
            'beneficiaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'beneficiaire_nom' => 'Test',
            'montant_brut' => 50_000,
            'total_deductions' => 0,
            'montant_net' => 50_000,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);

        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $this->actingAs($this->user)
            ->get(route('comptabilite.journal'))
            ->assertInertia(fn (Assert $page) => $page->has('lignes.data', 0));
    }
}
