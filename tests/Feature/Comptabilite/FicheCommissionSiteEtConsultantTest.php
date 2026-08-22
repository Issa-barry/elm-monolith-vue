<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\PrestataireType;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Models\PieceComptable;
use App\Models\Prestataire;
use App\Models\Site;
use App\Models\TiersComptable;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commission site et commission consultant (beneficiaire_type 'site'/'prestataire')
 * étaient générées par PeriodeCalculatorService::calculerSites()/calculerConsultants()
 * mais totalement ignorées par la comptabilité générale : FicheComptabilisationService::
 * TYPES_SUPPORTES ne couvrait que proprietaire/livreur (découvert lors de l'audit du
 * 2026-08-22, cf. suppression de JournalTresorerie). Ces tests verrouillent le
 * comportement une fois les deux types raccordés.
 */
class FicheCommissionSiteEtConsultantTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    /**
     * $periodeType (App\Enums\TypePeriodePaiement, ex: 'consultant') et
     * $beneficiaireType (PaiementFiche::beneficiaire_type, ex: 'prestataire')
     * divergent volontairement pour le type consultant — cf.
     * PeriodeCalculatorService::calculerConsultants() qui mappe
     * TypePeriodePaiement::CONSULTANT vers CommissionEnveloppePart::TYPE_PRESTATAIRE.
     */
    private function fiche(Organization $org, string $periodeType, string $beneficiaireType, $beneficiaire, float $brut, float $deductions = 0): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PER-'.uniqid(),
            'type' => $periodeType,
            'date_debut' => '2026-04-01',
            'date_fin' => '2026-04-30',
            'statut' => 'calculee',
        ]);

        return PaiementFiche::create([
            'organization_id' => $org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => $beneficiaireType,
            'beneficiaire_id' => $beneficiaire->id,
            'beneficiaire_nom' => $beneficiaire->nom ?? $beneficiaire->nom_complet,
            'montant_brut' => $brut,
            'total_deductions' => $deductions,
            'montant_net' => $brut - $deductions,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    private function makePrestataireConsultant(Organization $org): Prestataire
    {
        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => 'Diallo',
            'prenom' => 'Consultant',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Prestataire::create([
            'organization_id' => $org->id,
            'reference' => 'PRE-'.uniqid(),
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
    }

    public function test_fiche_site_validee_engage_la_charge_et_la_dette(): void
    {
        $org = $this->makeOrg();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'localisation' => 'Conakry']);
        $fiche = $this->fiche($org, 'site', 'site', $site, 120_000, 20_000);

        $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $this->assertNotNull($piece);
        $lignes = $piece->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);

        $this->assertEquals(120_000.0, (float) $lignes['622300']->debit); // charge commission site
        $this->assertEquals(100_000.0, (float) $lignes['467170']->credit); // dette site
        $this->assertEquals(20_000.0, (float) $lignes['467190']->credit); // avance récupérée

        $tiers = TiersComptable::where('tiersable_type', $site->getMorphClass())
            ->where('tiersable_id', $site->id)
            ->first();
        $this->assertNotNull($tiers);
        $this->assertSame('site', $tiers->type);
    }

    public function test_fiche_consultant_validee_engage_la_charge_et_la_dette(): void
    {
        $org = $this->makeOrg();
        $prestataire = $this->makePrestataireConsultant($org);
        $fiche = $this->fiche($org, 'consultant', 'prestataire', $prestataire, 90_000);

        $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $this->assertNotNull($piece);
        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('622400', $numeros); // charge commission consultant
        $this->assertContains('467180', $numeros); // dette consultant
    }

    public function test_paiement_commission_site_solde_la_dette_sans_recreer_de_charge(): void
    {
        $org = $this->makeOrg();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Kouria', 'type' => 'depot', 'localisation' => 'Conakry']);
        $fiche = $this->fiche($org, 'site', 'site', $site, 60_000);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 60_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-05-02',
        ]);

        $piecePaiement = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_SITE->value)
            ->first();

        $this->assertNotNull($piecePaiement);
        $numeros = $piecePaiement->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('467170', $numeros); // dette site soldée
        $this->assertContains('571000', $numeros); // caisse
        $this->assertNotContains('622300', $numeros); // aucune charge recréée au paiement
    }

    public function test_paiement_commission_consultant_solde_la_dette(): void
    {
        $org = $this->makeOrg();
        $prestataire = $this->makePrestataireConsultant($org);
        $fiche = $this->fiche($org, 'consultant', 'prestataire', $prestataire, 45_000);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 45_000,
            'mode_paiement' => 'virement',
            'date_paiement' => '2026-05-03',
        ]);

        $piecePaiement = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_CONSULTANT->value)
            ->first();

        $this->assertNotNull($piecePaiement);
        $numeros = $piecePaiement->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('467180', $numeros);
        $this->assertContains('521000', $numeros); // banque (virement)
    }

    public function test_suppression_du_paiement_contrepasse_la_piece(): void
    {
        $org = $this->makeOrg();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'siege', 'localisation' => 'Conakry']);
        $fiche = $this->fiche($org, 'site', 'site', $site, 30_000);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 30_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-05-04',
        ]);

        $piece = PieceComptable::where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->firstOrFail();

        $paiement->delete();

        $piece->refresh();
        $this->assertTrue($piece->statut->value === 'contrepassee');
        $this->assertNotNull(PieceComptable::where('piece_origine_id', $piece->id)->first());
    }
}
