<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\NatureOperation;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Garde-fou préventif à la création d'une commande de vente —
 * CommandeVenteController::ensurePartageLivraisonCategorieConfigure() bloque la création quand
 * l'équipe du véhicule sélectionné n'a pas de partage Livreur actif pour une catégorie dont le
 * barème équipe_livraison est positif sur le processus résolu (vente ou distribution client). Cf.
 * incident CMD-300826-007 (30/08/2026) : commande facturée et payée mais bloquée "à régulariser"
 * faute de config — ce garde-fou réduit le risque en le détectant dès la création, sans jamais
 * remplacer le filet de sécurité de la génération elle-même (cf.
 * CommissionEnveloppeGeneratorReglesTest / CommissionMoteurGeneriqueMultiProcessusTest, qui
 * restent la source de vérité du comportement "à régulariser" à la génération).
 */
class CommandeVentePartageLivraisonRequisTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Ce fichier teste le garde-fou de partage, pas la disponibilité du stock.
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille eau',
        ]);
    }

    private function makeProduit(?Categorie $categorie = null, string $nom = 'Pack Eau'): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => $nom, 'categorie_id' => ($categorie ?? $this->categorie)->id],
            ['prix_vente' => 5000, 'prix_usine' => 3500],
        );
    }

    private function makeVehiculeAvecEquipe(bool $livraisonVente = true, bool $livraisonLogistique = false): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'livraison_vente' => $livraisonVente,
            'livraison_logistique' => $livraisonLogistique,
        ]);

        EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);

        return $vehicule->fresh();
    }

    /**
     * Satisfait uniquement le garde-fou logistique de ensureNatureOperationCoherente()
     * (véhicule autorisé + livreur actif assigné), sans jamais créer de partage — utilisé par
     * les tests qui veulent isoler le garde-fou de partage testé par ce fichier de celui, distinct,
     * de l'usage logistique (cf. CommandeVenteController::ensureNatureOperationCoherente()).
     */
    private function assignChauffeurActif(Vehicule $vehicule): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);

        EquipeLivreur::create([
            'equipe_id' => $vehicule->equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
    }

    private function creerRegleEquipeLivraison(string $processusCode, int $montant): CommissionRegle
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, $processusCode);

        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function definirPartage(Vehicule $vehicule, string $processusCode, Categorie $categorie, int $montantUnitaire = 200): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, $processusCode);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        EquipeLivreur::create([
            'equipe_id' => $vehicule->equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $vehicule->equipe->id,
            'categorie_id' => $categorie->id,
            'processus_id' => $processus->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => $montantUnitaire,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function postVentes(array $payload)
    {
        return $this->actingAs($this->user)->post(route('ventes.store'), $payload);
    }

    // ── Processus vente ───────────────────────────────────────────────────────

    public function test_store_bloque_si_partage_manquant_pour_le_processus_vente(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_autorise_si_partage_configure_pour_le_processus_vente(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        $this->definirPartage($vehicule, CommissionProcessus::CODE_VENTE, $this->categorie);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── Processus distribution client ────────────────────────────────────────

    public function test_store_bloque_si_partage_manquant_pour_distribution_client(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(livraisonLogistique: true);
        $this->assignChauffeurActif($vehicule);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 200);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_store_autorise_si_partage_configure_pour_distribution_client(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(livraisonLogistique: true);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 200);
        $this->definirPartage($vehicule, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, $this->categorie);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    /**
     * Régression directe de l'incident : un partage configuré pour "vente" ne couvre jamais
     * "distribution_client" — chaque processus a son propre partage (cf. migration du
     * 30/08/2026, add_processus_id_to_equipe_livraison_partages_categorie_table).
     */
    public function test_store_bloque_distribution_meme_si_seul_le_partage_vente_existe(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(livraisonLogistique: true);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        $this->definirPartage($vehicule, CommissionProcessus::CODE_VENTE, $this->categorie);
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 200);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionHasErrors('vehicule_id');
    }

    // ── Enveloppe à 0 : jamais bloquant (décision AMOA #4) ───────────────────

    public function test_store_autorise_si_aucun_bareme_equipe_configure(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        // Aucune CommissionRegle equipe_livraison créée : enveloppe résolue = 0, rien à exiger.
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── Véhicule non éligible aux commissions ────────────────────────────────

    public function test_store_autorise_si_vehicule_non_eligible_aux_commissions(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(livraisonVente: false);
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        // Volontairement aucun partage configuré : le véhicule n'est de toute façon pas éligible
        // aux commissions (commission_eligible_snapshot resterait false).
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    // ── Plusieurs catégories, une seule manquante ────────────────────────────

    public function test_store_signale_uniquement_la_categorie_manquante_parmi_plusieurs(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $categorieManquante = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Sachet eau',
        ]);

        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        $this->definirPartage($vehicule, CommissionProcessus::CODE_VENTE, $this->categorie);

        $produitConfigure = $this->makeProduit($this->categorie);
        $produitManquant = $this->makeProduit($categorieManquante, 'Pack Sachet');

        $response = $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produitConfigure->id, 'qte' => 5, 'prix_vente' => 5000],
                ['produit_id' => $produitManquant->id, 'qte' => 3, 'prix_vente' => 5000],
            ],
        ]);

        $response->assertSessionHasErrors('vehicule_id');
        $message = session('errors')->get('vehicule_id')[0];
        $this->assertStringContainsString('Sachet eau', $message);
        $this->assertStringNotContainsString('Bouteille eau', $message);

        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    /**
     * Le message d'erreur ne doit jamais exposer un identifiant technique — uniquement le nom
     * métier de la catégorie (cf. régression constatée sur CMD-300826-007, où le message brut du
     * générateur affichait l'ULID de la catégorie à l'utilisateur).
     */
    public function test_message_erreur_utilise_le_nom_metier_jamais_lidentifiant(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $this->creerRegleEquipeLivraison(CommissionProcessus::CODE_VENTE, 200);
        $produit = $this->makeProduit();

        $this->postVentes([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 5, 'prix_vente' => 5000],
            ],
        ]);

        $message = session('errors')->get('vehicule_id')[0];
        $this->assertStringContainsString('Bouteille eau', $message);
        $this->assertStringNotContainsString($this->categorie->id, $message);
    }
}
