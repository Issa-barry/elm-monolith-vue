<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Tests du workflow de statut des commandes vente.
 *
 * Workflow : BROUILLON → A_CHARGER → CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS → LIVREE → CLOTUREE
 *                       ↘ ANNULEE (depuis BROUILLON ou A_CHARGER seulement)
 *
 * Routes testées :
 *   POST  /ventes/{id}/statut/avancer  (CommandeVenteStatutController::avancer)
 *   POST  /ventes/{id}/statut/annuler  (CommandeVenteStatutController::annuler)
 */
class CommandeVenteStatutTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    /**
     * Crée une commande avec un produit, un véhicule et une ligne, prête à avancer.
     *
     * @param  array<string, mixed>  $attrs  Surcharges pour CommandeVente::factory()
     * @return array{commande: CommandeVente, ligne: CommandeVenteLigne, produit: Produit, vehicule: Vehicule}
     */
    private function makeCommandeWithLigne(array $attrs = []): array
    {
        $produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit Test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_vente' => 2000,
            'prix_usine' => 1500,
        ]);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $commande = CommandeVente::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ], $attrs));

        $ligne = $commande->lignes()->create([
            'produit_id' => $produit->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        return compact('commande', 'ligne', 'produit', 'vehicule');
    }

    // ── BROUILLON → A_CHARGER ─────────────────────────────────────────────────

    public function test_avancer_confirme_brouillon_en_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
    }

    public function test_confirmer_exige_vehicule(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit', 'type' => 'materiel', 'statut' => 'actif',
            'prix_vente' => 2000, 'prix_usine' => 1500,
        ]);
        $commande->lignes()->create([
            'produit_id' => $produit->id, 'quantite_demandee' => 1,
            'prix_usine_snapshot' => 1500.0, 'prix_vente_snapshot' => 2000.0, 'total_ligne' => 2000.0,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    public function test_confirmer_exige_au_moins_une_ligne(): void
    {
        ['vehicule' => $vehicule] = $this->makeCommandeWithLigne();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── A_CHARGER → CHARGEMENT_EN_COURS ──────────────────────────────────────

    public function test_avancer_demarre_chargement_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
    }

    public function test_demarrer_chargement_cree_la_facture(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
            'total_commande' => 4000,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
        ]);
    }

    public function test_demarrer_chargement_cree_facture_avec_meme_reference(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $fresh = $commande->fresh();
        $facture = $fresh->facture;

        $this->assertNotNull($facture);
        $this->assertEquals($fresh->reference, $facture->reference);
    }

    // ── CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS ──────────────────────────────

    public function test_avancer_valide_chargement_avec_quantites(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 2,
                    'type_ecart' => 'conforme',
                    'commentaire_ecart' => null,
                ]],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $this->assertEquals(2, $ligne->fresh()->quantite_chargee);
    }

    public function test_avancer_valide_chargement_enregistre_ecart(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                    'commentaire_ecart' => 'Un pack endommagé',
                ]],
            ])
            ->assertRedirect();

        $freshLigne = $ligne->fresh();
        $this->assertEquals(1, $freshLigne->quantite_chargee);
        $this->assertEquals('manquant', $freshLigne->type_ecart->value);
        $this->assertEquals('Un pack endommagé', $freshLigne->commentaire_ecart);
    }

    public function test_valider_chargement_sans_quantite_chargee_retourne_erreur(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        // Pas de lignes envoyées → quantite_chargee reste null sur la ligne
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── Transition invalide depuis LIVRAISON_EN_COURS ─────────────────────────

    public function test_avancer_depuis_livraison_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
        ]);

        // La policy avancerStatut retourne false pour LIVRAISON_EN_COURS
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertStatus(403);
    }

    // ── Auto-transition LIVRAISON_EN_COURS → LIVREE via encaissement ──────────

    public function test_encaissement_auto_passe_livraison_en_cours_a_livree(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
            'montant_net' => 4000,
        ]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    public function test_encaissement_partiel_ne_cloture_pas(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
            'montant_net' => 4000,
        ]);

        // Encaissement partiel
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    // ── Auto-clôture LIVREE → CLOTUREE ────────────────────────────────────────

    public function test_encaissement_complet_depuis_livraison_cloture_la_commande(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
            'montant_net' => 4000,
        ]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 4000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
    }

    // ── Annulation via statut.annuler ──────────────────────────────────────────

    public function test_annuler_statut_depuis_brouillon(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'doublon',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_chargement_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertStatus(403);
    }

    public function test_annuler_statut_sans_motif_echoue(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation_code');
    }

    public function test_annuler_statut_avec_autre_exige_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
            ])
            ->assertSessionHasErrors('motif_annulation_detail');
    }

    public function test_annuler_statut_stocke_motif_et_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
                'motif_annulation_detail' => 'Stock manquant en entrepôt',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'motif_annulation' => 'autre : Stock manquant en entrepôt',
        ]);
    }
}
