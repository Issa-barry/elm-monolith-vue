<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Features\ModuleFeature;
use App\Models\CashbackTransaction;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\SolvabiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Test métier combiné bout-en-bout (cf. audit "Tarification client + Solvabilité + Dérogation
 * impayés" du 28/08/2026) : prouve que la tarification par nature de client, la responsabilité
 * financière (dette client vs véhicule), la dérogation client et la génération de commission
 * restent cohérentes ENSEMBLE dans un seul scénario réaliste. Chacune de ces règles est déjà
 * couverte isolément (CommandeVenteNaturePricingTest, SolvabiliteImpayesTest/Test,
 * CommissionTriggerVenteTest, ClientTest pour le cashback) — aucun test existant ne les exerce
 * simultanément sur la même commande, exactement le type de régression qui peut apparaître quand
 * ces chantiers évoluent séparément par la suite.
 */
class VenteRevendeurDerogationIntegrationTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create', 'ventes.update']);
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        // Déclencheur déterministe : commission à la validation du chargement (cf.
        // CommissionTriggerVenteTest, qui fixe le même déclencheur pour la même raison).
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);
    }

    /**
     * `categorie_id` doit correspondre à celle du barème d'équipe (cf. makeVehiculeAvecEquipe())
     * — c'est par cette catégorie que CommissionEnveloppeGenerator retrouve le partage
     * (EquipeLivraisonPartageCategorie) applicable à la ligne, cf. CommissionTriggerVenteTest::
     * makeProduit() qui suit exactement la même contrainte.
     */
    private function makeFabricable(Categorie $categorie): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable', 'categorie_id' => $categorie->id],
            [
                'prix_vente' => 22000,
                'prix_usine' => 18000,
                'prix_usine_tricycle' => 18000,
                'prix_externe' => 18250,
                'prix_revendeur' => 20000,
                'prix_distributeur' => 18500,
            ],
        );
    }

    /**
     * Barème + véhicule + équipe (chauffeur), même montage que CommissionTriggerVenteTest::
     * ensureBareme()/makeVehiculeAvecEquipe().
     *
     * @return array{0: Vehicule, 1: Categorie}
     */
    private function makeVehiculeAvecEquipe(): array
    {
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );
        $categorie = Categorie::firstOrCreate(
            ['organization_id' => $this->org->id, 'nom' => 'Défaut'],
            ['statut' => 'actif'],
        );
        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $this->org->id,
                'processus_id' => $processus->id,
                'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                'scope_type' => CommissionScopeType::GLOBAL->value,
            ],
            [
                'libelle' => 'Livraison — Global',
                'mode' => CommissionMode::A_REPARTIR->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => 300,
                'effective_from' => now()->subDay()->toDateString(),
                'statut' => 'active',
            ],
        );
        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $this->org->id,
                'processus_id' => $processus->id,
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => CommissionScopeType::GLOBAL->value,
            ],
            [
                'libelle' => 'Propriétaire — Global',
                'mode' => CommissionMode::DIRECT->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => 350,
                'effective_from' => now()->subDay()->toDateString(),
                'statut' => 'active',
            ],
        );

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $chauffeur->id, 'part_pourcentage' => 0,
            'montant_unitaire' => 300,
            'effective_from' => now()->subDay(),
        ]);

        return [$vehicule->fresh(), $categorie];
    }

    /** Facture IMPAYEE minimaliste — même montage que SolvabiliteServiceTest::makeFacture(). */
    private function makeFactureImpayee(int $montant, ?string $vehiculeId, ?string $clientId): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehiculeId,
            'client_id' => $clientId,
        ]);

        return FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehiculeId,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => StatutFactureVente::IMPAYEE->value,
        ]);
    }

    public function test_commande_fabricable_revendeur_en_derogation_avec_vehicule_reste_coherente_bout_en_bout(): void
    {
        // ── Contexte ──────────────────────────────────────────────────────────────
        // Seuil global volontairement TRÈS bas : si la dette du client (300 000) était comparée
        // à ce seuil, la commande serait bloquée. Elle ne doit passer que grâce au plafond
        // dérogatoire du client (500 000).
        Parametre::setVentesControleImpayes($this->org->id, true, 15_000);

        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
            'derogation_impayes_autorisee' => true,
            'seuil_derogation_impayes' => 500_000,
        ]);
        // Dette PRÉEXISTANTE du client, sous son plafond dérogatoire mais largement au-dessus du
        // seuil global — prouve que c'est bien la dérogation CLIENT qui autorise la commande.
        $this->makeFactureImpayee(300_000, null, $client->id);

        [$vehicule, $categorie] = $this->makeVehiculeAvecEquipe();
        // Dette PROPRE et énorme du véhicule (sans client), qui bloquerait n'importe quelle
        // commande si elle était consultée — preuve que le véhicule n'est plus qu'un support
        // logistique dès qu'un client est renseigné.
        $this->makeFactureImpayee(50_000_000, $vehicule->id, null);

        $produit = $this->makeFabricable($categorie);
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        // ── Création de la commande (véhicule ET client renseignés simultanément) ────
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 22000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)
            ->where('vehicule_id', $vehicule->id)
            ->latest()->firstOrFail();
        $ligne = $commande->lignes->first();

        // ── 1. Tarification : prix_revendeur gagne, jamais prix_usine ni prix_vente brut ───
        $this->assertSame('revendeur', $ligne->prix_origine_snapshot->value);
        $this->assertEquals(20000.0, (float) $ligne->prix_vente_snapshot);
        $this->assertEquals(40000.0, (float) $commande->total_commande); // 2 × 20000

        // ── 2. Solvabilité : plafond dérogatoire CLIENT utilisé, dette véhicule ignorée ────
        $resultat = app(SolvabiliteService::class)->evaluer($this->org->id, $vehicule->id, $client->id);
        $this->assertSame('client', $resultat['cible']);
        $this->assertSame('derogation', $resultat['seuil_origine']);
        $this->assertSame(500_000, $resultat['seuil_impayes']);
        $this->assertFalse($resultat['blocked'], 'le plafond dérogatoire du client doit autoriser malgré le seuil global très bas');

        // ── 3. Responsabilité financière : la facture porte vehicule_id (logistique/reporting)
        //      mais n'alourdit jamais la dette PROPRE du véhicule ────────────────────────────
        $facture = $commande->fresh('facture')->facture;
        $this->assertNotNull($facture);
        $this->assertSame($vehicule->id, $facture->vehicule_id, 'le véhicule reste rattaché à la facture pour le reporting/logistique');

        $resultatVehiculeSeul = app(SolvabiliteService::class)->evaluer($this->org->id, $vehicule->id, null);
        $this->assertSame('vehicule', $resultatVehiculeSeul['cible']);
        // La dette du véhicule reste EXACTEMENT celle préexistante (50 000 000) : la nouvelle
        // facture, bien que rattachée à ce véhicule, ne s'y ajoute jamais car sa commande porte
        // un client (cf. SolvabiliteService::facturesImpayeesVehicule()).
        $this->assertEquals(50_000_000, $resultatVehiculeSeul['total_remaining']);

        // ── 4. Commission : générée normalement pour l'équipe du véhicule, malgré le client débiteur ──
        $commande->refresh();
        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->statut);

        $this->actingAs($this->user);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande->fresh(), [[
            'id' => $ligne->id,
            'quantite_chargee' => 2,
            'type_ecart' => 'conforme',
        ]]);

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertNotEmpty($enveloppes, "la commission de l'équipe doit naître même quand la commande est facturée à un client en dérogation");

        // ── 5. Cashback : indépendant de la dérogation/dette, jamais réinitialisé par le cycle ──
        $this->assertTrue($client->fresh()->cashback_eligible);
    }

    /**
     * Test ciblé (cf. audit du 28/08/2026, section 4) : la nature "revendeur" du client ne doit
     * jamais interférer avec la résolution du plafond dérogatoire — resoudrePlafondClient() ne
     * regarde que derogation_impayes_autorisee/seuil_derogation_impayes, jamais `type`.
     */
    public function test_derogation_client_revendeur_nest_jamais_affectee_par_sa_nature(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);

        $revendeur = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'derogation_impayes_autorisee' => true,
            'seuil_derogation_impayes' => 2_000_000,
        ]);
        $externe = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'externe',
            'derogation_impayes_autorisee' => true,
            'seuil_derogation_impayes' => 2_000_000,
        ]);

        $this->makeFactureImpayee(1_500_000, null, $revendeur->id);
        $this->makeFactureImpayee(1_500_000, null, $externe->id);

        $resultatRevendeur = app(SolvabiliteService::class)->evaluer($this->org->id, null, $revendeur->id);
        $resultatExterne = app(SolvabiliteService::class)->evaluer($this->org->id, null, $externe->id);

        $this->assertSame('derogation', $resultatRevendeur['seuil_origine']);
        $this->assertSame(2_000_000, $resultatRevendeur['seuil_impayes']);
        $this->assertFalse($resultatRevendeur['blocked']);
        // Même plafond, même comportement, quelle que soit la nature — la dérogation ne dépend
        // que de Client::derogation_impayes_autorisee/seuil_derogation_impayes, jamais du type.
        $this->assertEquals($resultatExterne['seuil_origine'], $resultatRevendeur['seuil_origine']);
        $this->assertEquals($resultatExterne['seuil_impayes'], $resultatRevendeur['seuil_impayes']);
        $this->assertEquals($resultatExterne['blocked'], $resultatRevendeur['blocked']);
    }

    /**
     * Test métier combiné (cf. chantier cashback du 28/08/2026, section 13) : prouve que le
     * cashback — désormais une commission propre au client — reste cohérent avec la
     * tarification, la responsabilité financière et la commission de livraison dans un seul
     * scénario réaliste bout-en-bout, jusqu'à l'encaissement qui déclenche réellement le gain.
     */
    public function test_cashback_genere_a_lencaissement_sans_perturber_prix_dette_ni_commission(): void
    {
        Feature::for($this->org)->activate(ModuleFeature::CASHBACK);

        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 300,
        ]);
        [$vehicule, $categorie] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeFabricable($categorie);
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 20, 'prix_vente' => 22000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->firstOrFail();
        $ligne = $commande->lignes->first();

        // ── Tarification : prix_revendeur appliqué (20 × 20000, cf. makeFabricable()) ──────
        $this->assertSame('revendeur', $ligne->prix_origine_snapshot->value);
        $this->assertEquals(20000.0, (float) $ligne->prix_vente_snapshot);
        $this->assertEquals(400000.0, (float) $commande->total_commande);

        // ── Responsabilité financière : le client porte la dette (véhicule = logistique) ──
        $resultat = app(SolvabiliteService::class)->evaluer($this->org->id, $vehicule->id, $client->id);
        $this->assertSame('client', $resultat['cible']);

        // ── Chargement + livraison : déclenche la commission de l'équipe ──────────────────
        $this->actingAs($this->user);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande->fresh(), [[
            'id' => $ligne->id,
            'quantite_chargee' => 20,
            'type_ecart' => 'conforme',
        ]]);

        $this->assertNotEmpty(
            CommissionEnveloppe::where('source_id', $commande->id)->get(),
            "la commission de l'équipe de livraison doit naître normalement",
        );

        // ── Encaissement intégral : déclenche le cashback (jamais avant) ──────────────────
        $facture = $commande->fresh('facture')->facture;
        // Aucun cashback avant le paiement complet de la facture.
        $this->assertDatabaseCount('cashback_transactions', 0);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => $facture->montant_restant,
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        // ── Cashback : 20 packs livrés × 300 GNF/pack = 6 000 GNF, jamais lié au prix_revendeur ──
        $this->assertDatabaseHas('cashback_transactions', [
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 6000,
            'montant_unitaire_snapshot' => 300,
            'quantite_eligible_snapshot' => 20,
            'vente_id' => $commande->id,
        ]);
    }
}
