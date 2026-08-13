<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Une fiche de paiement gagne toujours la priorité sur le paiement direct
 * (FIFO) une fois qu'elle existe pour la période — sinon PaiementFiche.montant_paye
 * (qui ignore les paiements directs) permettrait un double paiement du même
 * bénéficiaire par les deux canaux. cf. retour utilisateur du 13/08/2026.
 */
class DoublePaiementLockTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        return Organization::factory()->create();
    }

    private function makePart(Organization $org, Livreur $livreur, string $earnedAt, float $montant = 10000.0): CommissionLogistiquePart
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $site = Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Dépôt Test'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );
        $sysUser = User::factory()->create(['organization_id' => $org->id]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'date_arrivee_reelle' => $earnedAt,
            'created_by' => $sysUser->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => $earnedAt,
            'periode' => PeriodeComptableService::codeForLivreur(Carbon::parse($earnedAt)),
        ]);
    }

    /** @test */
    public function paiement_direct_refuse_si_une_fiche_existe_deja_pour_la_periode(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $this->makePart($org, $livreur, '2026-04-12');

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $org->id,
            TypePeriodePaiement::LIVREUR,
            Carbon::parse('2026-04-12'),
        );
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE]);

        // La fiche existe déjà pour cette période — c'est désormais le seul canal autorisé.
        PaiementFiche::create([
            'organization_id' => $org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'montant_brut' => 10000,
            'total_deductions' => 0,
            'montant_net' => 10000,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fiche');

        CommissionPaymentService::payerLivreur($livreur->id, $org->id, 10000.0, 'especes', '2026-04-13');
    }

    /** @test */
    public function paiement_direct_autorise_tant_qu_aucune_fiche_n_existe(): void
    {
        $org = $this->makeOrg();
        $livreur = Livreur::factory()->create(['organization_id' => $org->id]);
        $this->makePart($org, $livreur, '2026-04-12');

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $org->id,
            TypePeriodePaiement::LIVREUR,
            Carbon::parse('2026-04-12'),
        );
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE]);

        // Aucune fiche générée pour cette période : le paiement direct reste le seul canal.
        $this->actingAs(User::factory()->create(['organization_id' => $org->id]));
        $paiement = CommissionPaymentService::payerLivreur($livreur->id, $org->id, 10000.0, 'especes', '2026-04-13');

        $this->assertEquals(10000.0, (float) $paiement->montant);
    }
}
