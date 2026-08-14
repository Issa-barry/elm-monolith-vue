<?php

namespace Tests\Feature;

use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Déclencheur configurable de la commission de vente — CommissionTriggerService /
 * DeclencheurCommissionVente. Le calcul lui-même (CommissionCalculator) n'est pas
 * retesté ici, seulement le QUAND (cf. CommandeVenteCommissionEligibiliteTest pour
 * le calcul).
 */
class CommissionTriggerVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeVehiculeAvecEquipe(?Organization $org = null): Vehicule
    {
        $org ??= $this->org;
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 70,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => 20,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'taux_commission' => 10,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);

        return $vehicule->fresh();
    }

    private function makeProduit(?Organization $org = null): Produit
    {
        return $this->makeProduitAvecVariante(
            $org ?? $this->org,
            ['nom' => 'Produit Test'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    /** @return array{commande: CommandeVente, ligne: CommandeVenteLigne} */
    private function creerCommandeAvecLigne(Vehicule $vehicule, Produit $produit, ?Site $site = null): array
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $site?->id ?? $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        return compact('commande', 'ligne');
    }

    /** Fait progresser la commande jusqu'à LIVRAISON_EN_COURS via le vrai service (pas de mock). */
    private function validerChargementComplet(CommandeVente $commande, CommandeVenteLigne $ligne): CommandeVente
    {
        $this->actingAs($this->user);

        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    private function encaisserIntegralement(CommandeVente $commande): void
    {
        $facture = $commande->fresh('facture')->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => $facture->montant_restant,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();
    }

    // ── CHARGEMENT_VALIDE ────────────────────────────────────────────────────

    public function test_chargement_valide_a_la_validation_du_chargement_genere_la_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->validerChargementComplet($commande, $ligne);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(StatutCommission::IMPAYE, $commission->statut);
    }

    public function test_chargement_valide_encaissement_ulterieur_ne_duplique_pas(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $this->encaisserIntegralement($commande);

        $this->assertEquals(
            1,
            CommissionVente::where('commande_vente_id', $commande->id)->count(),
            'L\'encaissement ne doit jamais générer une seconde commission.'
        );
    }

    // ── FACTURE_ENCAISSEE ────────────────────────────────────────────────────

    public function test_facture_encaissee_la_validation_du_chargement_ne_genere_aucune_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->validerChargementComplet($commande, $ligne);

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    public function test_facture_encaissee_encaissement_genere_une_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);

        $this->encaisserIntegralement($commande);

        $commission = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(StatutCommission::IMPAYE, $commission->statut);
    }

    public function test_facture_encaissee_encaissement_partiel_ne_genere_pas_de_commission(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);
        $facture = $commande->fresh('facture')->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => (float) $facture->montant_restant / 2,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    /** Double POST d'encaissement (retry) → une seule commission malgré 2 transitions vers PAYEE observées. */
    public function test_facture_encaissee_idempotence_sur_retry(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $commande = $this->validerChargementComplet($commande, $ligne);

        $this->encaisserIntegralement($commande);
        // Rejoue le recalcul de statut (ex: event modèle rejoué / job relancé) alors
        // que la facture est déjà PAYEE : ne doit pas re-générer.
        $commande->fresh('facture')->facture->recalculStatut();

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }

    // ── Multi-tenant ─────────────────────────────────────────────────────────

    public function test_parametre_organisation_est_independant(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);

        $orgB = Organization::factory()->create();
        Parametre::setDeclencheurCommissionVente($orgB->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        // Org A (FACTURE_ENCAISSEE) : rien au chargement.
        $vehiculeA = $this->makeVehiculeAvecEquipe();
        $produitA = $this->makeProduit();
        ['commande' => $commandeA, 'ligne' => $ligneA] = $this->creerCommandeAvecLigne($vehiculeA, $produitA);
        $this->validerChargementComplet($commandeA, $ligneA);

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commandeA->id]);

        // Vérifie la lecture indépendante du paramètre par organisation, sans
        // rejouer tout le cycle de vente pour l'organisation B (hors périmètre du
        // setUp() courant, scopé à $this->org).
        $this->assertEquals(
            DeclencheurCommissionVente::CHARGEMENT_VALIDE,
            Parametre::getDeclencheurCommissionVente($orgB->id),
        );
        $this->assertEquals(
            DeclencheurCommissionVente::FACTURE_ENCAISSEE,
            Parametre::getDeclencheurCommissionVente($this->org->id),
        );
    }

    public function test_defaut_sans_parametre_est_chargement_valide(): void
    {
        // Aucun Parametre::set... appelé pour cette organisation : le comportement
        // historique (CHARGEMENT_VALIDE) doit s'appliquer par défaut.
        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->validerChargementComplet($commande, $ligne);

        $this->assertDatabaseHas('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }
}
