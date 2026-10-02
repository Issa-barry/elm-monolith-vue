<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\ProduitVariante;
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

    private function makeFacture(CommandeVente $commande, int $net, int $encaisse = 0, array $overrides = []): FactureVente
    {
        $facture = FactureVente::create(array_merge([
            'organization_id' => $this->org->id,
            'vehicule_id' => $commande->vehicule_id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $net,
            'montant_net' => $net,
        ], $overrides));

        if ($encaisse > 0) {
            EncaissementVente::create([
                'facture_vente_id' => $facture->id,
                'montant' => $encaisse,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]);
        }

        return $facture->fresh();
    }

    private function makeLigne(CommandeVente $commande, ProduitVariante $variante, int $demandee, ?int $livree, int $total, ?string $libelle): void
    {
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $demandee,
            'quantite_livree' => $livree,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => $total,
            'libelle_snapshot' => $libelle,
        ]);
    }

    public function test_repartition_des_paiements_par_statut_de_facture(): void
    {
        $vehicule = $this->makeVehicule();

        $this->makeFacture($this->makeCommande($vehicule, ['total_commande' => 10000]), 10000, 10000);
        $this->makeFacture($this->makeCommande($vehicule, ['total_commande' => 6000]), 6000, 2000);
        $this->makeFacture($this->makeCommande($vehicule, ['total_commande' => 4000]), 4000);

        // Facture annulée : hors répartition, hors encaissé et reste à payer (comme sur l'écran Ventes).
        $this->makeFacture($this->makeCommande($vehicule, ['total_commande' => 50000]), 50000, 0, [
            'statut_facture' => StatutFactureVente::ANNULEE,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.paiements.total_montant', 20000)
                ->where('situation_ventes.paiements.total_ventes', 3)
                ->where('situation_ventes.paiements.repartition.0.code', 'paye')
                ->where('situation_ventes.paiements.repartition.0.montant', 10000)
                ->where('situation_ventes.paiements.repartition.0.pourcentage_montant', 50)
                ->where('situation_ventes.paiements.repartition.0.nb_ventes', 1)
                ->missing('situation_ventes.paiements.repartition.0.pourcentage_ventes')
                ->where('situation_ventes.paiements.repartition.0.reste_a_encaisser', 0)
                ->where('situation_ventes.paiements.repartition.1.code', 'partiel')
                ->where('situation_ventes.paiements.repartition.1.montant', 6000)
                ->where('situation_ventes.paiements.repartition.1.pourcentage_montant', 30)
                ->where('situation_ventes.paiements.repartition.1.nb_ventes', 1)
                ->where('situation_ventes.paiements.repartition.1.reste_a_encaisser', 4000)
                ->where('situation_ventes.paiements.repartition.2.code', 'impaye')
                ->where('situation_ventes.paiements.repartition.2.montant', 4000)
                ->where('situation_ventes.paiements.repartition.2.pourcentage_montant', 20)
                ->where('situation_ventes.paiements.repartition.2.nb_ventes', 1)
                ->where('situation_ventes.paiements.repartition.2.reste_a_encaisser', 4000)
                ->where('situation_ventes.kpis.encaisse', 12000)
                ->where('situation_ventes.kpis.reste_du', 8000)
            );
    }

    public function test_paiements_sans_vente_ne_divisent_pas_par_zero(): void
    {
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.paiements.total_montant', 0)
                ->where('situation_ventes.paiements.total_ventes', 0)
                ->where('situation_ventes.paiements.repartition.0.pourcentage_montant', 0)
                ->where('situation_ventes.paiements.repartition.2.pourcentage_montant', 0)
            );
    }

    public function test_produits_regroupes_par_variante_et_tries_par_quantite_decroissante(): void
    {
        $vehicule = $this->makeVehicule();
        $petit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 350ml'])->variantes()->first();
        $grand = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 1500ml'])->variantes()->first();

        $premiere = $this->makeCommande($vehicule);
        $this->makeLigne($premiere, $petit, 5, 5, 10000, 'Bouteille 350ml');
        // Ligne antérieure aux snapshots : repli sur le nom du produit.
        $this->makeLigne($premiere, $grand, 20, null, 40000, null);

        $seconde = $this->makeCommande($vehicule);
        $this->makeLigne($seconde, $petit, 9, 8, 16000, 'Bouteille 350ml');

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('situation_ventes.produits', 2)
                ->where('situation_ventes.produits.0.libelle', 'Bouteille 1500ml')
                ->where('situation_ventes.produits.0.quantite', 20)
                ->where('situation_ventes.produits.1.libelle', 'Bouteille 350ml')
                ->where('situation_ventes.produits.1.quantite', 13)
                ->where('situation_ventes.produits.1.montant', 26000)
            );
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

    public function test_expose_la_periode_active_et_aucun_historique_de_ventes(): void
    {
        $vehicule = $this->makeVehicule();
        $this->makeCommande($vehicule);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_periode.cle', 'tout')
                ->where('situation_periode.date_debut', null)
                ->where('situation_periode.date_fin', null)
                ->has('situation_periode.options', 10)
                ->missing('situation_ventes.ventes')
                ->missing('situation_ventes.periode_debut')
            );

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'situation_periode' => 'ce_mois']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_periode.cle', 'ce_mois')
                ->where('situation_periode.date_debut', now()->startOfMonth()->toDateString())
                ->where('situation_periode.date_fin', now()->endOfMonth()->toDateString())
            );

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'date_from' => '2026-09-01', 'date_to' => '2026-09-13']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_periode.cle', 'personnalisee')
                ->where('situation_periode.date_debut', '2026-09-01')
                ->where('situation_periode.date_fin', '2026-09-13')
            );
    }

    public function test_periode_rapide_ce_mois_exclut_les_ventes_anciennes(): void
    {
        $vehicule = $this->makeVehicule();

        $recente = $this->makeCommande($vehicule, ['total_commande' => 10000]);
        $recente->created_at = now();
        $recente->saveQuietly();

        $ancienne = $this->makeCommande($vehicule, ['total_commande' => 5000]);
        $ancienne->created_at = now()->subMonths(2);
        $ancienne->saveQuietly();

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'situation_periode' => 'ce_mois']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.ca_vendu', 10000)
                ->where('situation_ventes.kpis.nb_ventes', 1)
            );
    }

    public function test_une_seule_periode_filtre_toutes_les_sections_bornes_incluses(): void
    {
        $vehicule = $this->makeVehicule();
        $variante = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 500ml'])->variantes()->first();

        // [date de création, total, quantité, encaissé] — les deux premières sont sur les bornes
        // (1er septembre 00:10 et 13 septembre 23:30), les deux dernières juste en dehors.
        $ventes = [
            ['2026-09-01 00:10:00', 10000, 4, 10000],
            ['2026-09-13 23:30:00', 6000, 3, 2000],
            ['2026-08-31 23:59:00', 50000, 20, 0],
            ['2026-09-14 00:00:00', 70000, 30, 0],
        ];

        foreach ($ventes as [$creeeLe, $total, $quantite, $encaisse]) {
            $commande = $this->makeCommande($vehicule, ['total_commande' => $total]);
            $commande->created_at = $creeeLe;
            $commande->saveQuietly();
            $this->makeFacture($commande, $total, $encaisse);
            $this->makeLigne($commande, $variante, $quantite, $quantite, $total, 'Bouteille 500ml');
        }

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'date_from' => '2026-09-01', 'date_to' => '2026-09-13']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_ventes.kpis.ca_vendu', 16000)
                ->where('situation_ventes.kpis.nb_ventes', 2)
                ->where('situation_ventes.kpis.encaisse', 12000)
                ->where('situation_ventes.kpis.reste_du', 4000)
                ->has('situation_ventes.produits', 1)
                ->where('situation_ventes.produits.0.quantite', 7)
                ->where('situation_ventes.produits.0.montant', 16000)
                ->where('situation_ventes.paiements.total_ventes', 2)
                ->where('situation_ventes.paiements.total_montant', 16000)
                ->where('situation_ventes.paiements.repartition.0.montant', 10000)
                ->where('situation_ventes.paiements.repartition.1.montant', 6000)
                ->where('situation_ventes.paiements.repartition.2.montant', 0)
            );
    }

    public function test_periode_personnalisee_incomplete_affiche_toute_la_periode(): void
    {
        $vehicule = $this->makeVehicule();

        $ancienne = $this->makeCommande($vehicule, ['total_commande' => 5000]);
        $ancienne->created_at = now()->subYears(2);
        $ancienne->saveQuietly();
        $this->makeCommande($vehicule, ['total_commande' => 10000]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $vehicule, 'date_from' => '2026-09-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('situation_periode.cle', 'tout')
                ->where('situation_ventes.kpis.nb_ventes', 2)
            );
    }
}
