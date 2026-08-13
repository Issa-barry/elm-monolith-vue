<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutPieceComptable;
use App\Models\EcritureComptable;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\TiersComptable;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PeriodeComptableResolver;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\Comptabilite\RegularisationClotureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FicheComptabilisationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    private function fiche(Organization $org, string $type, $beneficiaire, float $brut, float $deductions, string $moisDebut = '2026-04-01', string $moisFin = '2026-04-30'): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PER-'.uniqid(),
            'type' => $type,
            'date_debut' => $moisDebut,
            'date_fin' => $moisFin,
            'statut' => 'calculee',
        ]);

        return PaiementFiche::create([
            'organization_id' => $org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaire->id,
            'beneficiaire_nom' => $beneficiaire->nom_complet,
            'montant_brut' => $brut,
            'total_deductions' => $deductions,
            'montant_net' => $brut - $deductions,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    /** @test */
    public function fiche_proprietaire_validee_engage_la_charge_et_la_dette_avec_retenue(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fiche($org, 'proprietaire', $proprietaire, 100000, 20000);

        $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $lignes = $piece->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);

        $this->assertEquals(100000.0, (float) $lignes['622100']->debit); // charge commission
        $this->assertEquals(80000.0, (float) $lignes['467110']->credit); // dette propriétaires
        $this->assertEquals(20000.0, (float) $lignes['467130']->credit); // avances récupérées

        $tiers = TiersComptable::where('tiersable_type', $proprietaire->getMorphClass())
            ->where('tiersable_id', $proprietaire->id)
            ->first();
        $this->assertNotNull($tiers);
        $this->assertSame('proprietaire', $tiers->type);
    }

    /** @test */
    public function fiche_livreur_validee_utilise_les_comptes_livreurs(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fiche($org, 'livreur', $livreur, 60000, 0);

        $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('622200', $numeros); // charge commission livreurs
        $this->assertContains('467120', $numeros); // dette livreurs
    }

    /** @test */
    public function paiement_fiche_solde_la_dette_sans_recreer_de_charge(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fiche($org, 'proprietaire', $proprietaire, 80000, 0);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 80000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-05-02',
        ]);

        $piecePaiement = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_PROPRIETAIRE->value)
            ->first();

        $this->assertNotNull($piecePaiement);
        $numeros = $piecePaiement->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('467110', $numeros); // dette soldée
        $this->assertContains('571000', $numeros); // caisse
        $this->assertNotContains('622100', $numeros); // aucune charge recréée au paiement
    }

    /** @test */
    public function regularisation_de_cloture_puis_validation_reelle_ne_double_compte_pas_la_charge(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        // Fiche CALCULEE mais pas encore VALIDEE au moment de la clôture d'août.
        $fiche = $this->fiche($org, 'proprietaire', $proprietaire, 90000, 0, '2026-08-01', '2026-08-31');

        $periodeComptable = app(PeriodeComptableResolver::class)->resolveOrCreate($org->id, Carbon::parse('2026-08-15'));
        $regularisees = app(RegularisationClotureService::class)->cloturerPeriode($periodeComptable, null);

        $this->assertCount(1, $regularisees);
        $regularisation = PieceComptable::findOrFail($regularisees[0]);
        $this->assertSame(StatutPieceComptable::VALIDEE, $regularisation->statut);

        // Validation réelle en septembre.
        $pieceDefinitive = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche->fresh());

        $this->assertNotNull($pieceDefinitive);
        $regularisation->refresh();
        $this->assertSame(StatutPieceComptable::CONTREPASSEE, $regularisation->statut);

        $contrepassation = PieceComptable::where('piece_origine_id', $regularisation->id)->first();
        $this->assertNotNull($contrepassation);

        // Effet net sur le compte de charge (622100) : régularisation (+90000) puis
        // contrepassation (-90000) s'annulent, seule l'écriture définitive (+90000)
        // reste — jamais 180000.
        $totalChargeDebit = EcritureComptable::query()
            ->whereHas('compte', fn ($q) => $q->where('numero', '622100'))
            ->whereIn('piece_comptable_id', [$regularisation->id, $contrepassation->id, $pieceDefinitive->id])
            ->sum('debit');
        $totalChargeCredit = EcritureComptable::query()
            ->whereHas('compte', fn ($q) => $q->where('numero', '622100'))
            ->whereIn('piece_comptable_id', [$regularisation->id, $contrepassation->id, $pieceDefinitive->id])
            ->sum('credit');

        $this->assertEqualsWithDelta(90000.0, (float) $totalChargeDebit - (float) $totalChargeCredit, 0.01);
    }
}
