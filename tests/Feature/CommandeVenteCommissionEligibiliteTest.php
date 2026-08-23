<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Éligibilité aux commissions — dérivée de Vehicule::livraison_vente et figée en snapshot sur
 * CommandeVente à sa création (cf. VehiculeCommandeContextResolver et CommissionEnveloppeGenerator).
 * Un véhicule de flotte facture toujours au prix de vente plein (mode_tarification_snapshot),
 * indépendamment de son éligibilité aux commissions — voir CommandeVenteModeTarificationTest
 * pour la tarification côté partenaire (sans véhicule).
 */
class CommandeVenteCommissionEligibiliteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Ce fichier teste l'ÉLIGIBILITÉ (livraison_vente) à la commission générée au moment du
        // chargement, indépendamment du déclencheur par défaut de l'organisation (devenu
        // FACTURE_ENCAISSEE le 18/08/2026, cf. Parametre::getDeclencheurCommissionVente()) — fixé
        // explicitement ici pour ne jamais dépendre de ce défaut.
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        // Ce fichier teste l'éligibilité aux commissions, pas la disponibilité du stock — le
        // produit par défaut (type 'materiel') n'est pas vendable, donc jamais compté par
        // CommandeVenteService::siteAutoriseNouvelleCommande() : sans cette politique
        // permissive, store() bloquerait la création dès la première ligne (24/08/2026,
        // contrôles de stock à la création/modification/chargement).
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
            'statut' => 'actif',
        ]);
    }

    private function makeProduit(int $prixVente = 5000, int $prixUsine = 3500): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau', 'categorie_id' => $this->categorie->id],
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

    /**
     * Équipe + barèmes réels (CommissionRegle PAR_UNITE_VENDUE + partage catégorie), nécessaires
     * pour que CommissionEnveloppeGenerator (seule voie de génération désormais) ne reste pas
     * silencieusement sans règle à résoudre — cf. CommissionRegleResolver::resolve().
     */
    private function attacherEquipe(Vehicule $vehicule, int $montantChauffeur = 667, int $montantConvoyeur = 333): void
    {
        $categorie = $this->categorie;

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $chauffeur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => $montantChauffeur,
            'effective_from' => now()->subDay(),
        ]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $convoyeur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => $montantConvoyeur,
            'effective_from' => now()->subDay(),
        ]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 1000,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 500,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
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
        // Le chargement (CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS, ci-dessous) décrémente
        // désormais réellement le stock du site — refusé depuis le 23/08/2026 si insuffisant
        // (suppression du clamp silencieux). Ce fichier teste l'éligibilité aux commissions,
        // pas la disponibilité du stock : on seed largement au-delà des 100 chargées, sur TOUS
        // les sites de l'organisation — initOrgAndUser() attache déjà un site par défaut en
        // interne, et $this->defaultSite ci-dessus en attache un second également marqué
        // is_default=true (pattern préexistant à ce fichier) : lequel des deux
        // getUserSiteModel() résout réellement pour la commande créée via ventes.store n'est
        // pas garanti, donc on couvre les deux plutôt que de fiabiliser cette ambiguïté
        // préexistante, hors périmètre de ce chantier stock.
        Site::where('organization_id', $this->org->id)->get()->each(
            fn (Site $site) => $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $site)
        );

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
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
        ]);
        $this->assertEquals(
            150_000,
            CommissionEnveloppe::where('source_id', $commande->id)->sum('montant_total')
        );
    }

    /**
     * Régression : le badge "Commission : X" de la page Commande a un moment lu une
     * relation qui pouvait rester vide selon le moteur actif (cf. getCommissionStatutGlobal()) —
     * il doit toujours refléter l'état réel de la commission générée.
     */
    public function test_page_commande_affiche_le_badge_commission(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: true);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ventes/Show')
                ->where('commission_statut.value', 'creee')
            );
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
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    // ── Immutabilité du snapshot ──────────────────────────────────────────────

    public function test_commission_eligible_snapshot_ne_change_pas_retroactivement_si_le_vehicule_change(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: false);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertFalse((bool) $commande->commission_eligible_snapshot);
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        // Le véhicule devient éligible aux commissions après coup.
        $vehicule->update(['livraison_vente' => true]);

        // Une commande déjà créée ne doit jamais être recalculée à partir de la
        // valeur courante du véhicule — seul le snapshot fait foi.
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertFalse((bool) $commande->fresh()->commission_eligible_snapshot);
    }

    public function test_commission_vente_non_generee_meme_si_vehicule_redevient_eligible_apres_coup(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(livraisonVente: true);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertDatabaseHas('commission_enveloppes', ['source_id' => $commande->id]);

        // Le véhicule devient inéligible après coup : la commission déjà
        // générée n'est jamais supprimée rétroactivement (aucun mécanisme ne
        // le fait, et ce n'est pas le rôle de CommissionEnveloppeGenerator, idempotent
        // par nature).
        $vehicule->update(['livraison_vente' => false]);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        // Une enveloppe par cible (propriétaire + équipe_livraison) — jamais dupliquée
        // par ce second appel idempotent.
        $this->assertEquals(2, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }
}
