<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

// ── Régression : index et show de Commission vente livreur divergeaient.
// L'index déduisait les frais (Depense validées) du net à payer, mais le show
// ne les chargeait jamais — et le plafond de paiement ne les déduisait pas non
// plus, ce qui permettait de payer le brut complet au lieu du reste réel. ─────

class CommissionVenteFraisTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    private function makeSite(): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Site $site): Vehicule
    {
        return Vehicule::create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'Camion Oumar',
            'immatriculation' => 'GN-'.uniqid(),
            'site_id' => $site->id,
            'categorie' => 'interne',
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'CAMARA',
            'prenom' => 'Oumar',
            'telephone' => '62200'.random_int(1000, 9999),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);
    }

    /**
     * Livreur avec une commission de 120 000 GNF (sans frais sur la part) et
     * une dépense externe validée de 100 000 GNF : le reste à payer attendu
     * partout est donc 20 000 GNF.
     */
    private function setupLivreurAvecFrais(float $montantDepense = 100000): Livreur
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();

        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $site->id,
            'statut' => 'livree',
            'total_commande' => 500000,
        ]);

        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => 'actif',
            ],
        );

        $commission = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 120000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'taux_repartition_snapshot' => 100,
            'montant_brut' => 120000,
            'montant_net' => 120000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $depenseType = DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'AVANCE',
            'libelle' => 'Avance sur commission',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ]);

        Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $depenseType->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => $montantDepense,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::VALIDE->value,
        ]);

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::LIVREUR,
            $commission->earned_at,
        );
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE]);

        return $livreur;
    }

    public function test_index_deduit_les_frais_du_net_a_payer(): void
    {
        $livreur = $this->setupLivreurAvecFrais();

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertEquals(120000, (float) $beneficiaire['total_brut_cumule']);
        $this->assertEquals(100000, (float) $beneficiaire['total_frais']);
        $this->assertEquals(20000, (float) $beneficiaire['total_net_cumule']);
        $this->assertEquals(20000, (float) $beneficiaire['solde_restant']);
    }

    public function test_show_affiche_exactement_les_memes_montants_que_index(): void
    {
        $livreur = $this->setupLivreurAvecFrais();

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->where('commission_summary.brut_cumule', 120000)
                ->where('commission_summary.frais', 100000)
                ->where('commission_summary.net_a_payer', 20000)
                ->where('commission_summary.reste_a_payer', 20000)
            );
    }

    // Paiement direct désormais impossible depuis cet écran (can_pay toujours false —
    // la seule chaîne de paiement valide passe par Comptabilité > Fiches de paiement,
    // cf. CommissionVenteController::index()). La déduction des frais du montant
    // réellement dû reste garantie côté génération de fiche
    // (PeriodeCalculatorService::calculerLivreurs(), cf. DepenseComptabiliteTest).
}
