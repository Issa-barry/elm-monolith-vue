<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionPart;
use App\Models\CommissionProcessus;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Garanties de coexistence pendant la transition (cf. demande explicite,
 * point 9) : le nouveau moteur ne doit JAMAIS altérer le comportement ou les
 * données de l'ancien système, qu'il soit inactif, en succès, ou en échec.
 */
class CommissionV2TransitionSafetyTest extends TestCase
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

    private function makeVehiculeAvecEquipe(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 70,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id,
            'taux_commission' => 30, 'role' => 'chauffeur', 'ordre' => 0,
        ]);

        return $vehicule->fresh();
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit Test'], ['prix_vente' => 2000, 'prix_usine' => 1500]);
    }

    private function creerCommandeValideeEtChargee(Vehicule $vehicule, Produit $produit): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 2 * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    // ── 1. V2 inactif → ancien fonctionnement inchangé (flux réel complet) ──

    /** @test */
    public function le_flux_reel_de_lancien_moteur_fonctionne_normalement_sans_aucun_processus_v2_seede(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();

        // Aucun CommissionProcessus n'existe pour cette organisation : le code V2
        // est présent dans le dépôt mais totalement absent du chemin d'exécution réel.
        $commande = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $ancienne = CommissionVente::where('commande_vente_id', $commande->id)->first();
        $this->assertNotNull($ancienne, "L'ancien moteur doit continuer à générer normalement.");
        $this->assertEquals(StatutCommission::CREEE, $ancienne->statut);
        $this->assertEquals(1000.0, (float) $ancienne->montant_commission_totale);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertDatabaseMissing('commission_generation_attempts', ['source_id' => $commande->id]);
    }

    // ── 2. Le replay ne modifie jamais l'historique ancien ──────────────────

    /** @test */
    public function generer_v2_sur_une_commande_deja_commissionnee_par_lancien_moteur_ne_modifie_rien_a_lancien(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        $commande = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $ancienne = CommissionVente::where('commande_vente_id', $commande->id)->firstOrFail();
        $anciennesParts = CommissionPart::where('commission_vente_id', $ancienne->id)->get();

        // Snapshot exact avant le passage du nouveau moteur (comme le ferait
        // `commissions:v2:rejouer`).
        $snapshotAvant = [
            'commission' => $ancienne->toArray(),
            'parts' => $anciennesParts->map->toArray()->all(),
        ];

        CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        CommissionEnveloppeGenerator::genererPourCommandeVenteMargeLegacy($commande);

        $ancienne->refresh();
        $anciennesPartsApres = CommissionPart::where('commission_vente_id', $ancienne->id)->get();

        $this->assertEquals($snapshotAvant['commission'], $ancienne->toArray(), 'Le nouveau moteur ne doit modifier aucun champ de l\'ancienne commission.');
        $this->assertEquals($snapshotAvant['parts'], $anciennesPartsApres->map->toArray()->all(), 'Le nouveau moteur ne doit modifier aucune ancienne part.');

        // Et le nouveau moteur a bien produit ses propres données, séparément.
        $this->assertDatabaseHas('commission_enveloppes', ['source_id' => $commande->id, 'cible_type' => 'proprietaire']);
    }

    // ── 3. Échec V2 → aucune modification de l'ancien système ───────────────

    /** @test */
    public function un_echec_v2_apres_generation_de_lancien_ne_touche_pas_a_lancien(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        $commande = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        $ancienne = CommissionVente::where('commande_vente_id', $commande->id)->firstOrFail();
        $snapshotAvant = $ancienne->toArray();

        CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        // Corrompt l'équipe pour forcer un échec de génération V2.
        $vehicule->loadMissing('equipe.membres');
        $vehicule->equipe->membres->first()->update(['taux_commission' => 5]);

        CommissionEnveloppeGenerator::genererPourCommandeVenteMargeLegacy($commande->fresh());

        $ancienne->refresh();
        $this->assertEquals($snapshotAvant, $ancienne->toArray());
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);

        $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail();
        $this->assertEquals(CommissionGenerationStatut::ERREUR, $tentative->statut);
    }

    // ── 4. Organisation non activée → V2 n'intervient jamais ────────────────

    /** @test */
    public function une_organisation_sans_processus_actif_ne_voit_jamais_le_nouveau_moteur_intervenir(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        // Processus seedé mais INACTIF (comportement par défaut de la commande de seed).
        CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::INACTIF->value,
        ]);

        $vehicule = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit();
        $commande = $this->creerCommandeValideeEtChargee($vehicule, $produit);

        // L'ancien moteur a fonctionné normalement au chargement (via le trigger réel).
        $this->assertDatabaseHas('commissions_ventes', ['commande_vente_id' => $commande->id]);

        CommissionEnveloppeGenerator::genererPourCommandeVenteMargeLegacy($commande->fresh());

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertDatabaseMissing('commission_generation_attempts', ['source_id' => $commande->id]);
    }
}
