<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutDepense;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class PaiementPeriodeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.manage']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    private function makePeriode(array $override = []): PaiementPeriode
    {
        return PaiementPeriode::create(array_merge([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202606-0001',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => StatutPeriodePaiement::BROUILLON->value,
            'created_by' => $this->user->id,
        ], $override));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.index'))
            ->assertStatus(200);
    }

    public function test_index_redirige_non_authentifie(): void
    {
        $this->get(route('comptabilite.periodes.index'))
            ->assertRedirect(route('login'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_creation_periode_livreur_valide(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.store'), [
                'type' => 'livreur',
                'date_debut' => '2026-06-01',
                'date_fin' => '2026-06-15',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $this->org->id,
            'type' => 'livreur',
            'statut' => StatutPeriodePaiement::BROUILLON->value,
        ]);
    }

    public function test_reference_generee_au_format_correct(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.store'), [
                'type' => 'livreur',
                'date_debut' => '2026-06-01',
                'date_fin' => '2026-06-15',
            ]);

        $periode = PaiementPeriode::where('organization_id', $this->org->id)->first();
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-\d{4}$/', $periode->reference);
    }

    public function test_store_echoue_sans_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.store'), [
                'date_debut' => '2026-06-01',
                'date_fin' => '2026-06-15',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_echoue_si_date_fin_avant_date_debut(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.store'), [
                'type' => 'livreur',
                'date_debut' => '2026-06-15',
                'date_fin' => '2026-06-01',
            ])
            ->assertSessionHasErrors('date_fin');
    }

    // ── calculer ──────────────────────────────────────────────────────────────

    public function test_calcul_genere_fiches_pour_livreurs_avec_commissions(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $site = $this->defaultSite();
        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'is_active' => true,
        ]);

        $commVente = \App\Models\CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $this->makeCommande()->id,
            'vehicule_id' => null,
            'montant_commande' => 1000000,
            'montant_commission_totale' => 300000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        CommissionPart::create([
            'commission_vente_id' => $commVente->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => 300000,
            'frais_supplementaires' => 0,
            'montant_net' => 300000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_fiches', [
            'periode_id' => $periode->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
        ]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->first();
        $this->assertNotNull($fiche);
        $this->assertGreaterThan(0, (float) $fiche->montant_net);

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->statut->value);
    }

    public function test_calcul_exclut_depenses_non_validees(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $site = $this->defaultSite();
        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => 'Barry',
            'prenom' => 'Ibrahima',
            'is_active' => true,
        ]);

        $commVente = \App\Models\CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $this->makeCommande()->id,
            'vehicule_id' => null,
            'montant_commande' => 500000,
            'montant_commission_totale' => 100000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        CommissionPart::create([
            'commission_vente_id' => $commVente->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => 100000,
            'frais_supplementaires' => 0,
            'montant_net' => 100000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $depType = DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'FUEL',
            'libelle' => 'Carburant',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ]);

        Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $depType->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 20000,
            'date_depense' => '2026-06-05',
            'statut' => StatutDepense::SOUMIS->value,
        ]);

        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode));

        $fiche = PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_id', $livreur->id)
            ->first();

        if ($fiche) {
            $this->assertSame(0.0, (float) $fiche->total_deductions);
        }
    }

    public function test_periode_validee_ne_peut_pas_etre_recalculee(): void
    {
        $periode = $this->makePeriode([
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);

        $this->expectException(\LogicException::class);

        app(\App\Services\PeriodeCalculatorService::class)->calculer($periode);
    }

    // ── valider ───────────────────────────────────────────────────────────────

    public function test_valider_periode_calculee(): void
    {
        $periode = $this->makePeriode([
            'statut' => StatutPeriodePaiement::CALCULEE->value,
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_periodes', [
            'id' => $periode->id,
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);
    }

    public function test_non_admin_ne_peut_pas_creer_periode(): void
    {
        Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $employe = User::factory()->create(['organization_id' => $this->org->id]);
        $employe->assignRole('employe');

        $this->actingAs($employe)
            ->post(route('comptabilite.periodes.store'), [
                'type' => 'livreur',
                'date_debut' => '2026-06-01',
                'date_fin' => '2026-06-15',
            ])
            ->assertStatus(403);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function makeCommande(): \App\Models\CommandeVente
    {
        $client = \App\Models\Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);

        return \App\Models\CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite()->id,
            'client_id' => $client->id,
            'reference' => 'CMD-TEST-'.uniqid(),
            'statut' => 'livre',
            'total_commande' => 1000000,
        ]);
    }
}
