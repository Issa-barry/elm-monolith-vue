<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aucun opérateur Mobile Money n'est codé en dur dans le moteur : "orange",
 * "mtn", "djomy" ne sont que des étiquettes libres. Le mapping suit un
 * pattern "mobile_money:{detail}" avec repli sur "mobile_money" générique.
 */
class MobileMoneyWalletTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    private function fichePayee(Organization $org, Proprietaire $proprietaire): PaiementFiche
    {
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
            'montant_brut' => 50000,
            'total_deductions' => 0,
            'montant_net' => 50000,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);

        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        return $fiche;
    }

    /** @test */
    public function un_paiement_orange_money_route_vers_le_compte_orange_dedie(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fichePayee($org, $proprietaire);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 50000,
            'mode_paiement' => 'mobile_money',
            'moyen_paiement_detail' => 'orange',
            'date_paiement' => '2026-05-01',
        ]);

        $piece = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_PROPRIETAIRE->value)
            ->first();

        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('561100', $numeros); // Orange Money dédié
        $this->assertNotContains('561000', $numeros); // pas le générique
    }

    /** @test */
    public function un_paiement_mobile_money_sans_detail_route_vers_le_compte_generique(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fichePayee($org, $proprietaire);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 50000,
            'mode_paiement' => 'mobile_money',
            'moyen_paiement_detail' => null,
            'date_paiement' => '2026-05-01',
        ]);

        $piece = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_PROPRIETAIRE->value)
            ->first();

        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('561000', $numeros); // générique
    }

    /** @test */
    public function un_wallet_non_configure_retombe_sur_le_compte_mobile_money_generique(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $fiche = $this->fichePayee($org, $proprietaire);

        // "djomy2" n'existe dans aucun mapping — doit retomber sur "mobile_money" générique
        // plutôt que d'échouer, exactement comme le prévoit CompteMappingResolver::candidats().
        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $org->id,
            'montant' => 50000,
            'mode_paiement' => 'mobile_money',
            'moyen_paiement_detail' => 'djomy2',
            'date_paiement' => '2026-05-01',
        ]);

        $piece = PieceComptable::query()
            ->where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_PROPRIETAIRE->value)
            ->first();

        $this->assertNotNull($piece);
        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
        $this->assertContains('561000', $numeros);
    }
}
