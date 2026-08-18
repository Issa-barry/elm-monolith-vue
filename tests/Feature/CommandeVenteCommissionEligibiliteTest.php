<?php

namespace Tests\Feature;

use App\Enums\DeclencheurCommissionVente;
use App\Models\CommandeVente;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommissionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Éligibilité aux commissions — dérivée de Vehicule::livraison_vente et figée en snapshot sur
 * CommandeVente à sa création (cf. VehiculeCommandeContextResolver et CommissionGenerator).
 * Un véhicule de flotte facture toujours au prix de vente plein (mode_tarification_snapshot),
 * indépendamment de son éligibilité aux commissions — voir CommandeVenteModeTarificationTest
 * pour la tarification côté partenaire (sans véhicule).
 */
class CommandeVenteCommissionEligibiliteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Ce fichier teste l'ÉLIGIBILITÉ (livraison_vente) à la commission générée au moment du
        // chargement, indépendamment du déclencheur par défaut de l'organisation (devenu
        // FACTURE_ENCAISSEE le 18/08/2026, cf. Parametre::getDeclencheurCommissionVente()) — fixé
        // explicitement ici pour ne jamais dépendre de ce défaut.
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeProduit(int $prixVente = 5000, int $prixUsine = 3500): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau'],
            ['prix_vente' => $prixVente, 'prix_usine' => $prixUsine],
        );
    }

    private function makeVehicule(bool $livraisonVente, int $capacite = 100): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'livraison_vente' => $livraisonVente,
        ]);
        // Capacité portée par le type (décision produit du 16/08/2026), jamais le véhicule.
        $vehicule->typeVehicule->update(['capacite_defaut' => $capacite]);

        return $vehicule;
    }

    /** Équipe à taux 100% (chauffeur + convoyeur + propriétaire), nécessaire pour que CommissionGenerator ne rejette pas la commande. */
    private function attacherEquipe(Vehicule $vehicule, float $tauxChauffeur = 20.0, float $tauxConvoyeur = 10.0): void
    {
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => round(100 - $tauxChauffeur - $tauxConvoyeur, 2),
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => $tauxChauffeur,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'taux_commission' => $tauxConvoyeur,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);
    }

    /**
     * Crée la commande puis fait progresser le workflow jusqu'à LIVRAISON_EN_COURS
     * (chargement validé) — c'est à cette étape, et seulement là, que la commission
     * naît sous le déclencheur par défaut CHARGEMENT_VALIDE (cf. CommandeVenteService::
     * validerChargement() / CommissionTriggerService).
     */
    private function creerCommande(Vehicule $vehicule, Produit $produit): CommandeVente
    {
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        // A_CHARGER → CHARGEMENT_EN_COURS.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $ligne = $commande->lignes()->first();

        // CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [
                    ['id' => $ligne->id, 'quantite_chargee' => 100, 'type_ecart' => 'conforme'],
                ],
            ])
            ->assertRedirect();

        return $commande->fresh();
    }

    // CommissionCalculator base son calcul sur prix_vente_snapshot -
    // prix_usine_snapshot (marge) : (5000-3500) × 100 = 150 000.

    public function test_vehicule_livraison_vente_genere_la_commission(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: true);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);

        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);
        $this->assertTrue((bool) $commande->commission_eligible_snapshot);
        $this->assertDatabaseHas('commissions_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_commission_totale' => 150_000,
        ]);
    }

    public function test_vehicule_sans_livraison_vente_ne_genere_pas_de_commission(): void
    {
        // Toujours facturé au prix de vente plein (véhicule de flotte gérée) mais aucune
        // commission — le véhicule n'est pas autorisé pour la vente.
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: false);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);

        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);
        $this->assertFalse((bool) $commande->commission_eligible_snapshot);
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
    }

    // ── Immutabilité du snapshot ──────────────────────────────────────────────

    public function test_commission_eligible_snapshot_ne_change_pas_retroactivement_si_le_vehicule_change(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: false);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertFalse((bool) $commande->commission_eligible_snapshot);
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);

        // Le véhicule devient éligible aux commissions après coup.
        $vehicule->update(['livraison_vente' => true]);

        // Une commande déjà créée ne doit jamais être recalculée à partir de la
        // valeur courante du véhicule — seul le snapshot fait foi.
        CommissionGenerator::generateForCommandeIfMissing($commande->fresh());

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
        $this->assertFalse((bool) $commande->fresh()->commission_eligible_snapshot);
    }

    public function test_commission_vente_non_generee_meme_si_vehicule_redevient_eligible_apres_coup(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: true);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertDatabaseHas('commissions_ventes', ['commande_vente_id' => $commande->id]);

        // Le véhicule devient inéligible après coup : la commission déjà
        // générée n'est jamais supprimée rétroactivement (aucun mécanisme ne
        // le fait, et ce n'est pas le rôle de CommissionGenerator, idempotent
        // par nature).
        $vehicule->update(['livraison_vente' => false]);
        CommissionGenerator::generateForCommandeIfMissing($commande->fresh());

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }
}
