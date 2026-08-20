<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

// ── Une CommissionEnveloppePart au statut "creee" (période de paiement pas
// encore validée — cf. CommissionAdjustmentService::activerCommissionsCreees())
// reste VISIBLE (décision produit du 20/08/2026 — « visible ne veut pas dire
// payable ») mais n'entre jamais dans le montant réellement exigible tant que
// sa période n'est pas validée : elle apparaît dans le compartiment
// en_attente_periode, jamais dans net_a_payer/reste_a_payer/payable. ─────────

class CommissionVenteStatutCreeeTest extends TestCase
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
            'nom_vehicule' => 'Camion Abdoulaye',
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
            'nom' => 'SYLLA',
            'prenom' => 'Abdoulaye',
            'telephone' => '62200'.random_int(1000, 9999),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);
    }

    /**
     * Livreur dont l'unique commission est encore au statut "creee" : la
     * commande n'a pas franchi la validation du chargement, la commission
     * n'est donc pas encore due.
     */
    private function setupLivreurAvecPartCreee(): Livreur
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();

        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $site->id,
            'statut' => 'chargement_en_cours',
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
            'montant_total' => 45000,
            'earned_at' => now(),
            'statut' => StatutCommission::CREEE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'taux_repartition_snapshot' => 100,
            'montant_brut' => 45000,
            'montant_net' => 45000,
            'montant_verse' => 0,
            'statut' => StatutCommission::CREEE->value,
        ]);

        return $livreur;
    }

    public function test_index_compte_les_parts_creee_en_attente_periode_pas_dans_le_reste_a_payer(): void
    {
        $livreur = $this->setupLivreurAvecPartCreee();

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertNotNull($beneficiaire, 'Un livreur dont la seule commission est "creee" doit rester visible dans la liste.');
        $this->assertEquals('creee', $beneficiaire['statut_global']);
        $this->assertEquals(45000, (float) $beneficiaire['total_genere']);
        $this->assertEquals(45000, (float) $beneficiaire['en_attente_periode']);
        $this->assertEquals(0.0, (float) $beneficiaire['payable']);
        $this->assertEquals(0.0, (float) $beneficiaire['solde_restant']);
    }

    public function test_show_affiche_la_part_creee_visible_mais_hors_montant_exigible(): void
    {
        $livreur = $this->setupLivreurAvecPartCreee();

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->where('commission_summary.net_a_payer', 0)
                ->where('commission_summary.reste_a_payer', 0)
                ->where('commission_summary.en_attente_periode', 45000)
                ->where('commission_summary.payable', 0)
                ->has('commission_details', 1)
                ->where('commission_details.0.statut', 'Créée')
            );
    }
}
