<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\PeriodePaiementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Vérifie que le badge de statut ("Impayé"/"En attente"/"En attente de validation")
 * et la payabilité exposés aux 4 catégories reflètent le statut de la PaiementPeriode
 * associée, et pas seulement le montant restant dû (voir PeriodePayabilityChecker).
 */
class CommissionStatutEffectifTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    private function validerPeriode(TypePeriodePaiement $type, $date, StatutPeriodePaiement $statut): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, $type, Carbon::parse($date));
        $periode->update(['statut' => $statut]);

        return $periode->fresh();
    }

    // ── Commission logistique ────────────────────────────────────────────────

    private function makeLogistiquePart(string $earnedAt): CommissionLogistiquePart
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 3000,
            'montant_total' => 3000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'taux_commission' => 60,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => $earnedAt,
        ]);
    }

    public function test_logistique_index_expose_en_attente_de_validation_si_periode_calculee(): void
    {
        $part = $this->makeLogistiquePart(now()->subDays(5)->toDateString());
        $this->validerPeriode(TypePeriodePaiement::LIVREUR, $part->earned_at, StatutPeriodePaiement::CALCULEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/logistique')
            ->assertInertia(fn ($page) => $page
                ->where('livreurs.0.statut_effectif', 'calculee')
                ->where('livreurs.0.statut_effectif_label', 'En attente de validation')
                ->where('livreurs.0.payable', false)
            );
    }

    public function test_logistique_index_expose_payable_si_periode_validee(): void
    {
        $part = $this->makeLogistiquePart(now()->subDays(5)->toDateString());
        $this->validerPeriode(TypePeriodePaiement::LIVREUR, $part->earned_at, StatutPeriodePaiement::VALIDEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/logistique')
            ->assertInertia(fn ($page) => $page
                ->where('livreurs.0.statut_effectif', 'impaye')
                ->where('livreurs.0.payable', true)
            );
    }

    // ── Commission vente ──────────────────────────────────────────────────────

    private function makeVentePart(string $type, string $beneficiaireNom): array
    {
        $commission = CommissionVente::factory()->create([
            'organization_id' => $this->org->id,
            'montant_commission_totale' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);

        $extra = $type === 'livreur'
            ? ['livreur_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id]
            : ['proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id];

        $part = $commission->parts()->create(array_merge([
            'type_beneficiaire' => $type,
            'beneficiaire_nom' => $beneficiaireNom,
            'taux_commission' => 100,
            'montant_brut' => 3000,
            'frais_supplementaires' => 0,
            'montant_net' => 3000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ], $extra));

        return ['commission' => $commission, 'part' => $part];
    }

    public function test_vente_index_expose_en_attente_de_validation_si_periode_calculee(): void
    {
        ['commission' => $commission] = $this->makeVentePart('livreur', 'Test Livreur');
        $this->validerPeriode(TypePeriodePaiement::LIVREUR, $commission->created_at, StatutPeriodePaiement::CALCULEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/vente')
            ->assertInertia(fn ($page) => $page
                ->where('beneficiaires.0.statut_effectif', 'calculee')
                ->where('beneficiaires.0.payable', false)
            );
    }

    public function test_vente_index_expose_payable_si_periode_validee(): void
    {
        ['commission' => $commission] = $this->makeVentePart('livreur', 'Test Livreur');
        $this->validerPeriode(TypePeriodePaiement::LIVREUR, $commission->created_at, StatutPeriodePaiement::VALIDEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/vente')
            ->assertInertia(fn ($page) => $page
                ->where('beneficiaires.0.statut_effectif', 'impaye')
                ->where('beneficiaires.0.payable', true)
            );
    }

    // ── Commission propriétaire ───────────────────────────────────────────────

    public function test_proprietaire_index_expose_en_attente_de_validation_si_periode_calculee(): void
    {
        ['commission' => $commission] = $this->makeVentePart('proprietaire', 'Test Proprio');
        $this->validerPeriode(TypePeriodePaiement::PROPRIETAIRE, $commission->created_at, StatutPeriodePaiement::CALCULEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/proprietaires')
            ->assertInertia(fn ($page) => $page
                ->where('beneficiaires.0.statut_effectif', 'calculee')
                ->where('beneficiaires.0.payable', false)
            );
    }

    public function test_proprietaire_index_expose_payable_si_periode_validee(): void
    {
        ['commission' => $commission] = $this->makeVentePart('proprietaire', 'Test Proprio');
        $this->validerPeriode(TypePeriodePaiement::PROPRIETAIRE, $commission->created_at, StatutPeriodePaiement::VALIDEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/proprietaires')
            ->assertInertia(fn ($page) => $page
                ->where('beneficiaires.0.statut_effectif', 'impaye')
                ->where('beneficiaires.0.payable', true)
            );
    }

    // ── Fiches de paiement ────────────────────────────────────────────────────

    private function makeFiche(StatutPeriodePaiement $statutPeriode): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-'.uniqid(),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => $statutPeriode->value,
            'created_by' => $this->user->id,
        ]);
        $site = $this->user->sites()->wherePivot('is_default', true)->first();

        $fiche = PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => 'fake-livreur-id',
            'beneficiaire_nom' => 'Diallo Mamadou',
            'site_id' => $site->id,
            'montant_brut' => 100000,
            'total_deductions' => 0,
            'montant_net' => 100000,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);

        PaiementFicheLigne::create([
            'fiche_id' => $fiche->id,
            'type_ligne' => 'commission_vente',
            'libelle' => 'Commission',
            'montant' => 100000,
            'ordre' => 1,
        ]);

        return $fiche;
    }

    public function test_fiches_index_expose_en_attente_de_validation_si_periode_calculee(): void
    {
        $this->makeFiche(StatutPeriodePaiement::CALCULEE);

        $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/fiches/livreurs')
            ->assertInertia(fn ($page) => $page
                ->where('fiches.data.0.statut_effectif', 'calculee')
                ->where('fiches.data.0.statut_effectif_label', 'En attente de validation')
                ->where('fiches.data.0.payable', false)
            );
    }

    public function test_fiches_show_payable_si_periode_validee(): void
    {
        $fiche = $this->makeFiche(StatutPeriodePaiement::VALIDEE);

        $this->actingAs($this->user)
            ->get("/backoffice/comptabilite/fiches/{$fiche->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fiche.statut_effectif', 'a_payer')
                ->where('fiche.payable', true)
            );
    }
}
