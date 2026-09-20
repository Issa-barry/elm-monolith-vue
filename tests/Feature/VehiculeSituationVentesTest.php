<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Onglet "Situation" de la fiche véhicule (Vehicules/Show → VehiculeSituationVentesService).
 * Couvre la règle produit verrouillée le 15/09/2026 : « vendu » exclut brouillon et annulée,
 * et la quantité vendue retombe sur quantite_demandee quand quantite_livree n'est pas
 * renseignée (vente sans étape de chargement/livraison) — cf. docs/vehicule-situation-ventes.md.
 */
class VehiculeSituationVentesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read']);
    }

    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
    }

    private function makeCommande(Vehicule $vehicule, array $overrides = []): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create(array_merge([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 10000,
            'reference' => 'SIT-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ], $overrides));
    }

    public function test_ca_vendu_exclut_brouillon_et_annulee(): void
    {
        $vehicule = $this->makeVehicule();

        $this->makeCommande($vehicule, ['statut' => StatutCommandeVente::LIVREE, 'total_commande' => 10000]);
        $this->makeCommande($vehicule, ['statut' => StatutCommandeVente::BROUILLON, 'total_commande' => 5000]);
        $this->makeCommande($vehicule, ['statut' => StatutCommandeVente::ANNULEE, 'total_commande' => 7000]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.ca_vendu', 10000)
                ->where('situation_ventes.kpis.nb_ventes', 1)
            );
    }

    public function test_encaisse_et_reste_du_viennent_de_la_facture(): void
    {
        $vehicule = $this->makeVehicule();
        $commande = $this->makeCommande($vehicule, ['total_commande' => 10000]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 10000,
            'montant_net' => 10000,
        ]);

        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 4000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.encaisse', 4000)
                ->where('situation_ventes.kpis.reste_du', 6000)
            );
    }

    public function test_quantite_vendue_utilise_quantite_livree_sinon_quantite_demandee(): void
    {
        $vehicule = $this->makeVehicule();
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Sachet 500ml']);
        $variante = $produit->variantes()->first();

        $avecLivraison = $this->makeCommande($vehicule);
        $avecLivraison->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 10,
            'quantite_livree' => 8,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 16000,
            'libelle_snapshot' => 'Sachet 500ml',
        ]);

        // Vente sans étape de chargement/livraison (ex. comptoir) : quantite_livree reste
        // null, la quantité vendue retombe sur quantite_demandee.
        $sansLivraison = $this->makeCommande($vehicule);
        $sansLivraison->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 5,
            'quantite_livree' => null,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 10000,
            'libelle_snapshot' => 'Sachet 500ml',
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.produits.0.quantite', 13)
                ->where('situation_ventes.produits.0.montant', 26000)
            );
    }

    public function test_ne_compte_pas_les_ventes_dun_autre_vehicule(): void
    {
        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule();

        $this->makeCommande($vehiculeA, ['total_commande' => 10000]);
        $this->makeCommande($vehiculeB, ['total_commande' => 99999]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehiculeA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.ca_vendu', 10000)
                ->where('situation_ventes.kpis.nb_ventes', 1)
            );
    }

    public function test_filtre_periode_mois_exclut_les_ventes_anciennes(): void
    {
        $vehicule = $this->makeVehicule();

        $recente = $this->makeCommande($vehicule, ['total_commande' => 10000]);
        $recente->created_at = now();
        $recente->saveQuietly();

        $ancienne = $this->makeCommande($vehicule, ['total_commande' => 5000]);
        $ancienne->created_at = now()->subMonths(2);
        $ancienne->saveQuietly();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'situation_periode' => 'month']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.ca_vendu', 10000)
                ->where('situation_ventes.kpis.nb_ventes', 1)
            );
    }
}
