<?php

namespace Tests\Feature;

use App\Enums\StatutFactureVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Verrou « première régularisation » (décision produit du 20/08/2026) — bout en bout, sur le
 * point d'entrée réel CommandeVenteController::store(). Le calcul lui-même (encaissé/restant,
 * dérogations, isolation multi-org) est couvert unitairement par
 * tests/Unit/SolvabiliteServiceTest ; ce fichier vérifie que le contrôleur applique bien ce
 * verrou à la création réelle d'une commande, avec le bon message, et que le correctif sur
 * CommandeVenteService::annuler() (facture annulée en cascade) débloque effectivement le
 * véhicule. Voir aussi PdvCheckoutTest pour l'équivalent côté PDV, sur le même service.
 */
class VehiculePremiereRegularisationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Défaut']);
    }

    private function site(): Site
    {
        return $this->user->sites()->firstOrFail();
    }

    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        // Pas de limite de chargement pour ces tests — seul le verrou de régularisation est visé.
        $vehicule->capacites()->create([
            'organization_id' => $this->org->id,
            'categorie_id' => $this->categorie->id,
            'capacite_max' => 99999,
        ]);

        return $vehicule;
    }

    private function payloadVente(Vehicule $vehicule): array
    {
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        return [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
        ];
    }

    /** Facture IMPAYEE (0 encaissé) rattachée à un véhicule, via sa commande. */
    private function makeFactureNonEncaissee(int $montant, Vehicule $vehicule, StatutFactureVente $statut = StatutFactureVente::IMPAYEE): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site()->id,
            'vehicule_id' => $vehicule->id,
        ]);

        return FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site()->id,
            'vehicule_id' => $vehicule->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut->value,
        ]);
    }

    // ── Cas 1 : aucune commande précédente ───────────────────────────────────

    public function test_store_autorise_quand_le_vehicule_na_aucune_commande_precedente(): void
    {
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    // ── Cas 2 : facture précédente totalement impayée ────────────────────────

    public function test_store_bloque_quand_la_facture_precedente_na_recu_aucun_paiement(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000_000); // seuil énorme : ne doit pas suffire
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFactureNonEncaissee(10_000, $vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionHasErrors('impayes');

        $errors = session('errors')->getBag('default')->get('impayes');
        $this->assertStringContainsString('aucun paiement', $errors[0]);
        $this->assertStringContainsString($facture->reference, $errors[0]);

        // Une seule commande pour ce véhicule : celle qui porte la facture non encaissée.
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /** Le verrou reste actif MÊME quand le contrôle de seuil général est désactivé (Cas 2 de la règle). */
    public function test_store_bloque_meme_quand_le_controle_des_impayes_est_desactive(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFactureNonEncaissee(10_000, $vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionHasErrors('impayes');

        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /** Une facture encore CREEE (commande à charger, chargement pas encore validé) bloque tout autant. */
    public function test_store_bloque_quand_la_facture_precedente_est_encore_creee(): void
    {
        $vehicule = $this->makeVehicule();
        $this->makeFactureNonEncaissee(10_000, $vehicule, StatutFactureVente::CREEE);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionHasErrors('impayes');
    }

    // ── Cas 3 : facture précédente partiellement payée ───────────────────────

    public function test_store_autorise_apres_premier_encaissement_partiel_controle_desactive(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFactureNonEncaissee(500_000, $vehicule, StatutFactureVente::PARTIEL);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 1,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_store_bloque_par_le_seuil_apres_encaissement_partiel_si_dette_restante_depasse(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFactureNonEncaissee(500_000, $vehicule, StatutFactureVente::PARTIEL);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 50_000, // reste 450 000 > seuil 100 000
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionHasErrors('impayes');

        // Message du contrôle de seuil habituel, pas celui du verrou absolu (déjà levé par
        // l'encaissement partiel) — comportement/message existant préservé (cf. règle métier §8).
        $errors = session('errors')->getBag('default')->get('impayes');
        $this->assertStringContainsString('depasse le seuil', $errors[0]);
    }

    // ── Cas 4 : facture précédente totalement payée ──────────────────────────

    public function test_store_autorise_quand_la_facture_precedente_est_totalement_payee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFactureNonEncaissee(500_000, $vehicule, StatutFactureVente::PAYEE);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 500_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionDoesntHaveErrors('impayes');
    }

    // ── Commande/facture annulée : ne représente plus de créance réelle ──────

    /**
     * Corrige une incohérence découverte pendant l'implémentation : avant ce correctif, annuler
     * une commande flotte depuis A_CHARGER laissait sa facture orpheline en statut CREEE (0
     * encaissé, solde > 0) — cf. CommandeVenteService::annuler(). Avec le nouveau verrou, cela
     * aurait bloqué le véhicule indéfiniment pour toute nouvelle commande.
     */
    public function test_annuler_une_commande_vehicule_debloque_immediatement_le_vehicule(): void
    {
        $vehicule = $this->makeVehicule();

        // Première commande : passe en A_CHARGER, facture créée en CREEE (0 encaissé).
        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionDoesntHaveErrors();

        $premiere = CommandeVente::where('vehicule_id', $vehicule->id)->firstOrFail();
        $this->assertTrue($premiere->isACharger());
        $this->assertNotNull($premiere->facture);
        $this->assertSame(StatutFactureVente::CREEE, $premiere->facture->statut_facture);

        // Sans l'annuler, une deuxième commande serait bloquée par le verrou.
        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionHasErrors('impayes');

        // Annulation de la première commande — doit annuler sa facture en cascade.
        $this->actingAs($this->user)
            ->patch(route('ventes.annuler', $premiere), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(StatutFactureVente::ANNULEE, $premiere->facture->fresh()->statut_facture);

        // Le véhicule peut désormais recevoir une nouvelle commande.
        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payloadVente($vehicule))
            ->assertSessionDoesntHaveErrors('impayes');

        // 2 commandes au total pour ce véhicule (la première annulée + la nouvelle), une seule
        // encore active.
        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->where('statut', '!=', 'annulee')->count());
    }

    // ── Cohérence aperçu (check-solvabilite) / création réelle ──────────────

    public function test_check_solvabilite_annonce_le_verrou_premiere_facture(): void
    {
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFactureNonEncaissee(10_000, $vehicule);

        $apercu = $this->actingAs($this->user)
            ->get('/backoffice/ventes/check-solvabilite?vehicule_id='.$vehicule->id)
            ->assertOk()
            ->json();

        $this->assertTrue($apercu['blocked']);
        $this->assertTrue($apercu['blocage_premiere_facture']);
        $this->assertSame($facture->reference, $apercu['facture_bloquante_reference']);
    }
}
