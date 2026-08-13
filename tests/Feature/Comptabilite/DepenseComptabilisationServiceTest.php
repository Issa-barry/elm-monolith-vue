<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EcritureComptable;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepenseComptabilisationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    /** @test */
    public function depense_interne_validee_est_comptabilisee_comme_une_vraie_charge(): void
    {
        $org = $this->makeOrg();
        $type = DepenseType::factory()->interne()->create(['organization_id' => $org->id]);
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $org->id,
            'depense_type_id' => $type->id,
            'montant' => 25000,
        ]);

        $depense->update(['statut' => 'valide']);

        $piece = PieceComptable::query()
            ->where('source_type', $depense->getMorphClass())
            ->where('source_id', $depense->id)
            ->where('type_evenement', EvenementComptable::DEPENSE_INTERNE_VALIDEE->value)
            ->first();

        $this->assertNotNull($piece);
        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('628800', $numeros); // charge diverse par défaut
        $this->assertContains('571000', $numeros); // caisse
    }

    /** @test */
    public function depense_vehicule_validee_est_une_avance_pas_une_charge(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $type = DepenseType::factory()->vehicule()->create(['organization_id' => $org->id]);
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $org->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'montant' => 20000,
        ]);

        $depense->update(['statut' => 'valide']);

        $piece = PieceComptable::query()
            ->where('source_type', $depense->getMorphClass())
            ->where('source_id', $depense->id)
            ->where('type_evenement', EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE->value)
            ->first();

        $this->assertNotNull($piece);
        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('467130', $numeros); // avance propriétaires (créance)
        $this->assertNotContains('622100', $numeros); // jamais une charge de commission
        $this->assertNotContains('628800', $numeros); // jamais une charge diverse non plus
    }

    /** @test */
    public function avance_puis_fiche_ne_double_compte_jamais_la_meme_depense(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $type = DepenseType::factory()->vehicule()->create(['organization_id' => $org->id]);
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $org->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'montant' => 20000,
        ]);
        $depense->update(['statut' => 'valide']);

        $periode = PaiementPeriode::create([
            'organization_id' => $org->id,
            'reference' => 'PER-'.uniqid(),
            'type' => 'proprietaire',
            'date_debut' => '2026-04-01',
            'date_fin' => '2026-04-30',
            'statut' => 'calculee',
        ]);
        $fiche = PaiementFiche::create([
            'organization_id' => $org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'proprietaire',
            'beneficiaire_id' => $proprietaire->id,
            'beneficiaire_nom' => $proprietaire->nom_complet,
            'montant_brut' => 100000,
            'total_deductions' => 20000,
            'montant_net' => 80000,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        // Le compte "avances propriétaires" doit être soldé : 20000 débités par la
        // dépense, 20000 crédités par la fiche — solde net = 0 pour ce tiers.
        $soldeAvance = EcritureComptable::query()
            ->whereHas('compte', fn ($q) => $q->where('numero', '467130'))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as solde')
            ->value('solde');

        $this->assertEqualsWithDelta(0.0, (float) $soldeAvance, 0.01);

        // Le compte de charge ne porte que la charge de la fiche (100000), jamais
        // une seconde fois la dépense de 20000 déjà imputée en avance.
        $chargeTotale = EcritureComptable::query()
            ->whereHas('compte', fn ($q) => $q->where('numero', '622100'))
            ->sum('debit');

        $this->assertEqualsWithDelta(100000.0, (float) $chargeTotale, 0.01);
    }
}
