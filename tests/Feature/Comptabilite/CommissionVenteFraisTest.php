<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        return Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => 'CAMARA',
            'prenom' => 'Oumar',
            'telephone' => '62200'.random_int(1000, 9999),
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

        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => 500000,
            'montant_commission_totale' => 120000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'role' => 'chauffeur',
            'taux_commission' => 100,
            'montant_brut' => 120000,
            'frais_supplementaires' => 0,
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

    public function test_paiement_refuse_un_montant_superieur_au_reste_apres_frais(): void
    {
        $livreur = $this->setupLivreurAvecFrais();

        // Le brut de la part (120 000) dépasse le reste réel (20 000) une fois
        // les frais déduits : le paiement doit être bloqué.
        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $livreur->id), [
                'montant' => 100000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');
    }

    public function test_paiement_du_reste_exact_apres_frais_est_accepte(): void
    {
        $livreur = $this->setupLivreurAvecFrais();

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $livreur->id), [
                'montant' => 20000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
    }

    // ── Message d'erreur explicite quand le solde disponible est nul ─────────

    public function test_message_explique_que_les_frais_ont_ramene_le_solde_a_zero(): void
    {
        // Frais (120 000) >= brut (120 000) : le solde disponible tombe à 0,
        // le message doit le dire explicitement plutôt qu'un simple "(0.00 GNF)".
        $livreur = $this->setupLivreurAvecFrais(montantDepense: 120000);

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $livreur->id), [
                'montant' => 1000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');

        $this->assertStringContainsString(
            'frais',
            mb_strtolower($response->getSession()->get('errors')->get('montant')[0])
        );
    }
}
