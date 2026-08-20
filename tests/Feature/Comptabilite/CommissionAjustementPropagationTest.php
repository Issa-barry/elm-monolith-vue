<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\MotifAjustementCommission;
use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Un ajustement validé (montant_actuel) doit se propager immédiatement partout où un montant
 * de commission est affiché ou payé — listes, détails, paiement direct — pas seulement dans la
 * génération de PaiementFiche (déjà couverte par CommissionAjustementTest::test_calcul_de_fiche_utilise_le_montant_ajuste).
 * Avant ce correctif, CommissionVenteController/CommissionLogistiqueController/CommissionProprietaireController
 * et CommissionVentePaiementService lisaient encore montant_net brut, ignorant tout ajustement validé.
 */
class CommissionAjustementPropagationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    private function makeLivreur(string $nom = 'Diallo'): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => "Mamadou {$nom}",
            'is_active' => true,
        ]);
    }

    private ?CommissionProcessus $processusVente = null;

    /** @return array{commission: CommissionEnveloppe, part: CommissionEnveloppePart, livreur: ?Livreur} */
    private function makeCommissionVenteAvecPart(float $montantNet, string $type = 'livreur', ?Proprietaire $proprietaire = null): array
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);
        $site = $this->user->sites()->wherePivot('is_default', true)->first();

        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'reference' => 'CMD-TEST-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1000000,
        ]);

        $this->processusVente ??= CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $commission = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processusVente->id,
            'cible_type' => $type === 'livreur' ? CommissionCibleType::CODE_EQUIPE_LIVRAISON : CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montantNet,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $livreur = $type === 'livreur' ? $this->makeLivreur() : null;

        $part = CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $livreur?->id ?? $proprietaire?->id,
            'montant_brut' => $montantNet,
            'montant_net' => $montantNet,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return ['commission' => $commission, 'part' => $part, 'livreur' => $livreur];
    }

    private function validerPeriode(TypePeriodePaiement $type, $date, StatutPeriodePaiement $statut): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, $type, Carbon::parse($date));
        $periode->update(['statut' => $statut->value]);

        return $periode->fresh();
    }

    // ── Listes / détails : le montant affiché doit être le montant ajusté ──────

    public function test_index_vente_reflete_le_montant_ajuste(): void
    {
        ['part' => $part, 'livreur' => $livreur] = $this->makeCommissionVenteAvecPart(45000.0);

        CommissionAdjustmentService::ajusterMontant($part, 46500.0, MotifAjustementCommission::TRAVAIL_SUPPLEMENTAIRE, null, $this->user);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.index'));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertNotNull($row);
        $this->assertSame(46500.0, $row['total_net_cumule'], 'le net cumulé doit refléter le montant ajusté, pas le théorique (45000)');
        $this->assertSame(46500.0, $row['solde_restant']);
    }

    public function test_show_livreur_vente_reflete_le_montant_ajuste(): void
    {
        ['part' => $part, 'livreur' => $livreur] = $this->makeCommissionVenteAvecPart(15000.0);

        CommissionAdjustmentService::ajusterMontant($part, 12000.0, MotifAjustementCommission::ABSENCE, null, $this->user);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id]));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(12000.0, $props['commission_summary']['net_a_payer']);
        $this->assertSame(12000.0, $props['commission_summary']['reste_a_payer']);
        $this->assertSame(12000.0, $props['commission_details'][0]['montant'], 'le détail par commande doit aussi refléter le montant ajusté');
    }

    public function test_index_proprietaire_reflete_le_montant_ajuste(): void
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bah',
            'prenom' => 'Ibrahima',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
        ]);
        ['part' => $part] = $this->makeCommissionVenteAvecPart(60000.0, 'proprietaire', $proprietaire);

        CommissionAdjustmentService::ajusterMontant($part, 61500.0, MotifAjustementCommission::TRAVAIL_SUPPLEMENTAIRE, null, $this->user);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.proprietaires.index'));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', (string) $proprietaire->id);

        $this->assertNotNull($row);
        $this->assertSame(61500.0, $row['total_net_cumule']);
        $this->assertSame(61500.0, $row['solde_restant']);
    }

    public function test_index_logistique_reflete_le_montant_ajuste(): void
    {
        $livreur = $this->makeLivreur();
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->wherePivot('is_default', true)->first();
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'created_by' => $this->user->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 20000,
            'montant_total' => 20000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $part = CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => 20000,
            'frais_supplementaires' => 0,
            'montant_net' => 20000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
            'earned_at' => now(),
        ]);

        CommissionAdjustmentService::ajusterMontantLogistique($part, 18000.0, MotifAjustementCommission::ABSENCE, null, $this->user);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.logistique.index'));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['livreurs'])
            ->firstWhere('livreur_id', $livreur->id);

        $this->assertNotNull($row);
        $this->assertSame(18000.0, $row['impaye'], 'le solde impayé doit refléter le montant ajusté (18000), pas le théorique (20000)');
    }

    // Paiement direct de commission vente désormais impossible depuis aucun écran
    // (can_pay toujours false — la seule chaîne de paiement valide passe par
    // Comptabilité > Fiches de paiement) : les scénarios "respecte le montant
    // ajusté" n'ont plus de route à exercer ici. La propagation de l'ajustement
    // au montant réellement dû par une fiche reste couverte par
    // CommissionAjustementTest::test_calcul_de_fiche_utilise_le_montant_ajuste.
}
