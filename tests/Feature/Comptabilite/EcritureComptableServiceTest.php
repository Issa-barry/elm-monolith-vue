<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Exceptions\Comptabilite\EcritureDesequilibreeException;
use App\Exceptions\Comptabilite\MappingComptableIndisponibleException;
use App\Exceptions\Comptabilite\PeriodeComptableClotureeException;
use App\Models\CompteMapping;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PeriodeComptableResolver;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EcritureComptableServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(bool $bootstrap = true): Organization
    {
        $org = Organization::factory()->create();
        if ($bootstrap) {
            // Idempotent — déjà bootstrapé automatiquement par Organization::created(),
            // appelé explicitement pour ne pas dépendre de ce hook dans ce test.
            app(PlanComptableBootstrapService::class)->bootstrap($org->id);
        } else {
            // Le hook Organization::created() bootstrape désormais toute organisation
            // créée : pour simuler un plan comptable non configuré (scénario que la
            // création normale ne permet plus d'atteindre), on retire volontairement
            // les mappings auto-provisionnés plutôt que de sauter le bootstrap.
            CompteMapping::where('organization_id', $org->id)->delete();
        }

        return $org;
    }

    private function fichePropriétaire(Organization $org, float $brut, float $deductions): PaiementFiche
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);

        $periode = PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PER-'.uniqid(),
            'type' => 'proprietaire',
            'date_debut' => '2026-04-01',
            'date_fin' => '2026-04-30',
            'statut' => 'calculee',
        ]);

        return PaiementFiche::create([
            'organization_id' => $org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'proprietaire',
            'beneficiaire_id' => $proprietaire->id,
            'beneficiaire_nom' => $proprietaire->nom_complet,
            'montant_brut' => $brut,
            'total_deductions' => $deductions,
            'montant_net' => $brut - $deductions,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    /** @test */
    public function meme_evenement_traite_deux_fois_ne_cree_qu_une_seule_piece(): void
    {
        $org = $this->makeOrg();
        $fiche = $this->fichePropriétaire($org, 100000, 0);
        $service = app(FicheComptabilisationService::class);

        $piece1 = $service->comptabiliserFicheValidee($fiche);
        $piece2 = $service->comptabiliserFicheValidee($fiche);

        $this->assertSame($piece1->id, $piece2->id);
        $this->assertSame(1, PieceComptable::query()
            ->where('source_type', $fiche->getMorphClass())
            ->where('source_id', $fiche->id)
            ->where('type_evenement', EvenementComptable::FICHE_PROPRIETAIRE_VALIDEE->value)
            ->count());
    }

    /** @test */
    public function une_piece_generee_est_toujours_equilibree(): void
    {
        $org = $this->makeOrg();
        $fiche = $this->fichePropriétaire($org, 100000, 15000);
        $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        $this->assertEqualsWithDelta(0.0, $piece->totalDebit() - $piece->totalCredit(), 0.001);
        $this->assertEquals(100000.0, $piece->totalDebit());
    }

    /** @test */
    public function une_ligne_avec_debit_et_credit_nuls_est_rejetee_sans_rien_persister(): void
    {
        $org = $this->makeOrg();
        $depense = Depense::factory()->create(['organization_id' => $org->id]);

        $this->expectException(EcritureDesequilibreeException::class);

        try {
            app(EcritureComptableService::class)->comptabiliser(
                evenement: EvenementComptable::DEPENSE_INTERNE_VALIDEE,
                source: $depense,
                organizationId: $org->id,
                dateComptable: Carbon::now(),
                libelle: 'test ligne invalide',
                lignes: [
                    ['role' => 'charge_defaut', 'sens' => 'debit', 'montant' => 0],
                    ['role' => 'tresorerie', 'sens' => 'credit', 'montant' => 0],
                ],
            );
        } finally {
            $this->assertSame(0, PieceComptable::query()->where('organization_id', $org->id)->count());
        }
    }

    /** @test */
    public function une_periode_cloturee_refuse_une_nouvelle_piece_ordinaire(): void
    {
        $org = $this->makeOrg();
        $date = Carbon::parse('2026-04-10');

        $periode = app(PeriodeComptableResolver::class)->resolveOrCreate($org->id, $date);
        $periode->update(['statut' => 'cloturee']);

        // fichePropriétaire() crée une PaiementPeriode couvrant tout avril 2026,
        // soit exactement la période comptable clôturée ci-dessus.
        $fiche = $this->fichePropriétaire($org, 50000, 0);

        $this->expectException(PeriodeComptableClotureeException::class);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);
    }

    /** @test */
    public function la_numerotation_est_sequentielle_et_sans_collision(): void
    {
        $org = $this->makeOrg();
        $numeros = [];

        for ($i = 0; $i < 5; $i++) {
            $fiche = $this->fichePropriétaire($org, 10000 + $i, 0);
            $piece = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);
            $numeros[] = $piece->numero;
        }

        $this->assertSame($numeros, array_unique($numeros));
        $this->assertSame(5, PieceComptable::query()->where('organization_id', $org->id)->count());
    }

    /** @test */
    public function une_organisation_non_bootstrappee_ne_peut_pas_comptabiliser(): void
    {
        $orgSansMapping = $this->makeOrg(bootstrap: false);
        $fiche = $this->fichePropriétaire($orgSansMapping, 50000, 0);

        $this->expectException(MappingComptableIndisponibleException::class);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);
    }

    /** @test */
    public function les_mappings_d_une_organisation_ne_fuient_pas_vers_une_autre(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg(bootstrap: false);

        $ficheA = $this->fichePropriétaire($orgA, 30000, 0);
        $pieceA = app(FicheComptabilisationService::class)->comptabiliserFicheValidee($ficheA);

        $this->assertSame($orgA->id, $pieceA->organization_id);
        $this->assertSame(0, PieceComptable::query()->where('organization_id', $orgB->id)->count());
    }
}
