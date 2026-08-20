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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionVenteEquipeTest extends TestCase
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

    private function makeLivreur(string $prenom, string $nom): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => $prenom,
            'telephone' => '62200'.random_int(1000, 9999),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);
    }

    /**
     * Crée une enveloppe de commission vente avec une part chauffeur + une part
     * convoyeur, comme le ferait CommissionEnveloppeGenerator pour une équipe à 2 membres.
     */
    private function makeCommissionEquipe(
        Vehicule $vehicule,
        Site $site,
        Livreur $chauffeur,
        Livreur $convoyeur,
        float $commissionTotale = 31_660.0,
        float $tauxChauffeur = 18.42,
        float $tauxConvoyeur = 13.16,
    ): CommissionEnveloppe {
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
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
            'cible_id' => (string) \Illuminate\Support\Str::ulid(),
            'montant_total' => $commissionTotale,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $montantChauffeur = round($commissionTotale * $tauxChauffeur / 100, 2);
        $montantConvoyeur = round($commissionTotale * $tauxConvoyeur / 100, 2);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $chauffeur->id,
            'taux_repartition_snapshot' => $tauxChauffeur,
            'montant_brut' => $montantChauffeur,
            'montant_net' => $montantChauffeur,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $convoyeur->id,
            'taux_repartition_snapshot' => $tauxConvoyeur,
            'montant_brut' => $montantConvoyeur,
            'montant_net' => $montantConvoyeur,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return $commission;
    }

    // ── Liste — les 2 membres de l'équipe apparaissent ────────────────────────

    public function test_liste_commission_vente_affiche_chauffeur_et_convoyeur(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $chauffeur = $this->makeLivreur('Yamousse', 'CAMARA');
        $convoyeur = $this->makeLivreur('Mamadouba', 'CAMARA');

        $this->makeCommissionEquipe($vehicule, $site, $chauffeur, $convoyeur);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Index')
                ->has('beneficiaires', 2)
            );
    }

    public function test_liste_commission_vente_montants_respectent_le_taux_de_chaque_membre(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $chauffeur = $this->makeLivreur('Yamousse', 'CAMARA');
        $convoyeur = $this->makeLivreur('Mamadouba', 'CAMARA');

        $this->makeCommissionEquipe($vehicule, $site, $chauffeur, $convoyeur);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $beneficiaires = collect($response->viewData('page')['props']['beneficiaires'])
            ->keyBy('beneficiaire_id');

        $this->assertEquals(5831.77, round((float) $beneficiaires[$chauffeur->id]['total_net_cumule'], 2));
        $this->assertEquals(4166.46, round((float) $beneficiaires[$convoyeur->id]['total_net_cumule'], 2));
    }

    // ── Détail — chaque livreur voit sa propre ligne de commande ─────────────

    public function test_detail_livreur_chauffeur_affiche_sa_ligne_de_commande(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $chauffeur = $this->makeLivreur('Yamousse', 'CAMARA');
        $convoyeur = $this->makeLivreur('Mamadouba', 'CAMARA');

        $commission = $this->makeCommissionEquipe($vehicule, $site, $chauffeur, $convoyeur);
        $commandeReference = $commission->source->reference;

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $chauffeur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->has('commission_details', 1)
                ->where('commission_details.0.reference', $commandeReference)
                ->where('commission_details.0.montant', 5831.77)
            );
    }

    public function test_detail_livreur_convoyeur_affiche_sa_ligne_de_commande(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $chauffeur = $this->makeLivreur('Yamousse', 'CAMARA');
        $convoyeur = $this->makeLivreur('Mamadouba', 'CAMARA');

        $commission = $this->makeCommissionEquipe($vehicule, $site, $chauffeur, $convoyeur);
        $commandeReference = $commission->source->reference;

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $convoyeur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->has('commission_details', 1)
                ->where('commission_details.0.reference', $commandeReference)
                ->where('commission_details.0.montant', 4166.46)
            );
    }
}
