<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\EcritureComptable;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Avant l'audit du 2026-08-22, CommissionPayment (paiement direct de
 * commission logistique — circuit encore actif via
 * Comptabilite\CommissionLogistiqueController (écran Comptabilité > Commissions >
 * Logistique ; /backoffice/logistique/commissions a été retiré le 04/09/2026, cf.
 * docs/commissions.md), distinct de PaiementFiche et verrouillé contre le double
 * paiement par PeriodePayabilityChecker::assertPartsNotClaimedByFiche) n'avait aucune
 * écriture dans compta_ecritures. Ce test verrouille le raccordement.
 */
class CommissionPaymentComptabilisationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);
    }

    private function makePartPayable(float $montant): CommissionLogistiquePart
    {
        $site = $this->user->sites()->first();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre', 'type' => 'depot', 'localisation' => 'Conakry']);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $site->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $site->id,
            'site_destination_id' => $autreSite->id,
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
        $personne = Personne::create(['organization_id' => $this->org->id, 'telephone' => '+224'.fake()->unique()->numerify('#########')]);
        $livreur = Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'nom_complet' => 'Livreur Test', 'is_active' => true]);

        // La période couvrant earned_at doit être validée pour que le paiement soit
        // autorisé (PeriodePayabilityChecker::assertPartPayable).
        [$debut] = PeriodePaiementService::dateRangeFor(2026, 8, PeriodePaiementService::P1);
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, $debut);
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => 'Livreur Test',
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => '2026-08-05',
        ]);
    }

    public function test_paiement_commission_logistique_genere_une_ecriture_equilibree(): void
    {
        $this->actingAs($this->user);
        $part = $this->makePartPayable(150_000);

        $payment = CommissionPaymentService::payerLivreur(
            livreurId: $part->livreur_id,
            orgId: $this->org->id,
            montant: 150_000,
            modePaiement: 'especes',
            paidAt: now()->toDateString(),
        );

        $compteCharge = CompteComptable::where('organization_id', $this->org->id)->where('numero', '622200')->firstOrFail();
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $ligneCharge = EcritureComptable::where('compte_comptable_id', $compteCharge->id)
            ->whereHas('piece', fn ($q) => $q->where('source_id', $payment->id))
            ->firstOrFail();
        $this->assertSame(150_000.0, (float) $ligneCharge->debit);

        $ligneTresorerie = EcritureComptable::where('compte_comptable_id', $compteCaisse->id)
            ->whereHas('piece', fn ($q) => $q->where('source_id', $payment->id))
            ->firstOrFail();
        $this->assertSame(150_000.0, (float) $ligneTresorerie->credit);
    }

    public function test_paiement_commission_logistique_echoue_si_comptabilisation_impossible(): void
    {
        $part = $this->makePartPayable(150_000);

        CompteMapping::where('organization_id', $this->org->id)
            ->where('evenement', 'paiement_commission_logistique_direct')
            ->delete();

        try {
            CommissionPaymentService::payerLivreur(
                livreurId: $part->livreur_id,
                orgId: $this->org->id,
                montant: 150_000,
                modePaiement: 'especes',
                paidAt: now()->toDateString(),
            );
            $this->fail('Une exception RuntimeException était attendue.');
        } catch (\RuntimeException $e) {
            // attendu
        }

        $this->assertDatabaseMissing('commission_payments', ['livreur_id' => $part->livreur_id]);
    }
}
