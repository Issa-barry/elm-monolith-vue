<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

// ── Régression : une CommissionPart au statut "creee" (commande dont le
// chargement n'a pas encore été validé — CommandeVenteService::
// activerFactureEtCommissions() ne l'a pas encore activée) n'est PAS payable
// (CommissionVentePaiementService::partsDisponibles() l'exclut explicitement).
// L'index/le détail ne doivent donc pas la compter dans le "reste à payer",
// sous peine de promettre à l'écran un solde que le paiement va ensuite
// rejeter ("solde disponible : 0.00 GNF"). ────────────────────────────────────

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
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        return Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => 'SYLLA',
            'prenom' => 'Abdoulaye',
            'telephone' => '62200'.random_int(1000, 9999),
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

        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => 500000,
            'montant_commission_totale' => 45000,
            'montant_verse' => 0,
            'statut' => StatutCommission::CREEE->value,
        ]);

        CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'role' => 'chauffeur',
            'taux_commission' => 100,
            'montant_brut' => 45000,
            'frais_supplementaires' => 0,
            'montant_net' => 45000,
            'montant_verse' => 0,
            'statut' => StatutCommission::CREEE->value,
        ]);

        return $livreur;
    }

    public function test_index_ne_compte_pas_les_parts_creee_dans_le_reste_a_payer(): void
    {
        $livreur = $this->setupLivreurAvecPartCreee();

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaire = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);

        $this->assertNull($beneficiaire, 'Un livreur dont la seule commission est "creee" ne doit pas apparaître dans la liste.');
    }

    public function test_show_affiche_un_resume_a_zero_pour_une_commission_creee(): void
    {
        $livreur = $this->setupLivreurAvecPartCreee();

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->where('resume_global.total_net_cumule', 0)
                ->where('resume_global.solde_global', 0)
                ->has('historique_commandes', 0)
            );
    }

    public function test_paiement_refuse_tant_que_la_commission_est_creee(): void
    {
        $livreur = $this->setupLivreurAvecPartCreee();

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $livreur->id), [
                'montant' => 1,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors('montant');

        // Le message doit expliquer la cause (chargement non validé), pas
        // juste afficher "solde disponible : 0.00 GNF".
        $this->assertStringContainsString(
            'chargement',
            mb_strtolower($response->getSession()->get('errors')->get('montant')[0])
        );
    }
}
