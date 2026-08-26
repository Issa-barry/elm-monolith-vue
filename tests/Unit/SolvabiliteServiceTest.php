<?php

namespace Tests\Unit;

use App\Enums\StatutFactureVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Services\SolvabiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Source de vérité UNIQUE du contrôle des impayés (cf. docblock SolvabiliteService) — ces
 * tests couvrent directement evaluer()/enforcerOuEchouer(), réutilisés à l'identique par
 * CommandeVenteController (back-office) et PdvCheckoutService (PDV), cf. tests Feature dédiés
 * pour la couverture HTTP de chacun de ces deux appelants.
 */
class SolvabiliteServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private SolvabiliteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->service = new SolvabiliteService;
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            ...$overrides,
        ]);
    }

    /** Facture IMPAYEE/PARTIEL rattachée à un véhicule et/ou un client, via sa commande. */
    private function makeFacture(int $montantNet, StatutFactureVente $statut, ?string $vehiculeId = null, ?string $clientId = null): FactureVente
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
            'montant_brut' => $montantNet,
            'montant_net' => $montantNet,
            'statut_facture' => $statut->value,
        ]);
    }

    private function encaisser(FactureVente $facture, int $montant): void
    {
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);
    }

    // ── Seuil global (pas de dérogation véhicule) ───────────────────────────────

    public function test_vehicule_sans_derogation_utilise_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 500_000);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => false]);
        // Encaissement de 1 GNF : neutralise le verrou « première régularisation » (testé à part,
        // cf. section dédiée plus bas) sans changer le reste à payer (500 001 - 1 = 500 000),
        // pour isoler ici le seul comportement du seuil à l'égalité.
        $facture = $this->makeFacture(500_001, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame('vehicule', $resultat['cible']);
        $this->assertSame(500_000, $resultat['seuil_impayes']);
        $this->assertFalse($resultat['blocked'], 'dette = seuil → autorisé, jamais bloqué à l\'égalité');
    }

    // ── Dérogation individuelle par véhicule (décision produit du 22/08/2026) ────────────────

    public function test_vehicule_avec_derogation_utilise_son_propre_plafond(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        // Encaissement de 1 GNF : neutralise le verrou « première régularisation » sans changer
        // le reste à payer (1 500 001 - 1 = 1 500 000), pour isoler ici le seul comportement du
        // plafond dérogatoire.
        $facture = $this->makeFacture(1_500_001, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(2_000_000, $resultat['seuil_impayes']);
        $this->assertSame('derogation', $resultat['seuil_origine']);
        $this->assertFalse($resultat['blocked'], 'sous le plafond dérogatoire du véhicule → autorisé');
        $this->assertSame(500_000, $resultat['montant_disponible']);
    }

    public function test_vehicule_avec_derogation_bloque_au_dela_de_son_propre_plafond(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 10_000_000);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        $this->makeFacture(2_000_001, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertTrue($resultat['blocked'], 'le plafond du véhicule prime sur un seuil global bien plus large');
        $this->assertSame(1, $resultat['depassement']);
    }

    /**
     * Deux véhicules du MÊME type, avec des plafonds dérogatoires différents, doivent réellement
     * produire des plafonds différents — la dérogation ne dépend jamais du type (cf. exemple
     * ABARRY/autre Minibus de l'analyse du 22/08/2026).
     */
    public function test_deux_vehicules_du_meme_type_ont_des_plafonds_derogatoires_independants(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehiculeA = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        $vehiculeB = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 10_000_000]);

        $this->assertSame(2_000_000, $this->service->evaluer($this->org->id, $vehiculeA->id, null)['seuil_impayes']);
        $this->assertSame(10_000_000, $this->service->evaluer($this->org->id, $vehiculeB->id, null)['seuil_impayes']);
    }

    /**
     * Filet de sécurité : un véhicule dérogatoire sans plafond propre configuré ne doit jamais
     * être traité comme illimité — retombe sur le seuil global. Ce cas est normalement empêché
     * côté formulaire (VehiculeController::ensureDerogationCoherente()), mais le service doit
     * rester sûr même si le plafond est retiré après coup.
     */
    public function test_vehicule_avec_derogation_mais_sans_plafond_configure_retombe_sur_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 500_000);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => null]);
        $this->makeFacture(600_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(500_000, $resultat['seuil_impayes'], 'pas de plafond configuré → filet de sécurité, jamais illimité');
        $this->assertSame('standard', $resultat['seuil_origine']);
        $this->assertTrue($resultat['blocked']);
    }

    /** Même type, deux véhicules : la dérogation reste individuelle, jamais automatique pour tout le type. */
    public function test_deux_vehicules_du_meme_type_un_avec_derogation_lautre_sans(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $avecDerogation = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 3_000_000]);
        $sansDerogation = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => false]);
        // avecDerogation : encaissement de 1 GNF pour neutraliser le verrou « première
        // régularisation » et isoler le comportement du plafond dérogatoire (reste à payer
        // inchangé : 1 000 001 - 1 = 1 000 000). sansDerogation reste fully impayée : de toute
        // façon bloqué (seuil global 0), avec ou sans le nouveau verrou.
        $facture = $this->makeFacture(1_000_001, StatutFactureVente::PARTIEL, $avecDerogation->id);
        $this->encaisser($facture, 1);
        $this->makeFacture(1_000_000, StatutFactureVente::IMPAYEE, $sansDerogation->id);

        $this->assertFalse($this->service->evaluer($this->org->id, $avecDerogation->id, null)['blocked']);
        $this->assertTrue($this->service->evaluer($this->org->id, $sansDerogation->id, null)['blocked']);
    }

    /** Changer le type d'un véhicule dérogatoire n'affecte plus jamais son plafond — désormais individuel, pas hérité. */
    public function test_changement_de_type_naffecte_jamais_le_plafond_derogatoire_du_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $tricycle = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $camion = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule(['type_vehicule_id' => $tricycle->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);

        $vehicule->update(['type_vehicule_id' => $camion->id]);

        $this->assertSame(2_000_000, $this->service->evaluer($this->org->id, $vehicule->id, null)['seuil_impayes']);
    }

    /** Modifier le plafond d'un véhicule n'affecte jamais un autre véhicule, même du même type. */
    public function test_modification_du_plafond_dun_vehicule_naffecte_jamais_un_autre(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehiculeA = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        $vehiculeB = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);

        $vehiculeA->update(['seuil_derogation_impayes' => 3_000_000]);

        $this->assertSame(3_000_000, $this->service->evaluer($this->org->id, $vehiculeA->id, null)['seuil_impayes']);
        $this->assertSame(2_000_000, $this->service->evaluer($this->org->id, $vehiculeB->id, null)['seuil_impayes']);
    }

    // ── Comportement strict du seuil : dette = seuil autorisé, dette > seuil bloqué ─

    public function test_dette_egale_au_seuil_est_autorisee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 2_000_000);
        $vehicule = $this->makeVehicule();
        // Encaissement de 1 GNF : neutralise le verrou « première régularisation » sans changer
        // le reste à payer (2 000 001 - 1 = 2 000 000), pour isoler le comportement du seuil.
        $facture = $this->makeFacture(2_000_001, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $this->assertFalse($this->service->evaluer($this->org->id, $vehicule->id, null)['blocked']);
    }

    public function test_dette_superieure_au_seuil_est_bloquee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 2_000_000);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(2_000_001, StatutFactureVente::IMPAYEE, $vehicule->id);

        $this->assertTrue($this->service->evaluer($this->org->id, $vehicule->id, null)['blocked']);
    }

    public function test_seuil_zero_avec_dette_zero_est_autorise(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocked']);
        $this->assertFalse($resultat['has_debt']);
    }

    public function test_seuil_zero_avec_la_moindre_dette_est_bloque(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(1, StatutFactureVente::IMPAYEE, $vehicule->id);

        $this->assertTrue($this->service->evaluer($this->org->id, $vehicule->id, null)['blocked']);
    }

    /** Seuil standard à 0 mais plafond dérogatoire du véhicule au-dessus : le véhicule dérogatoire n'est pas bloqué. */
    public function test_seuil_standard_zero_avec_derogation_du_vehicule_au_dessus(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 3_000_000]);
        // Encaissement de 1 GNF : neutralise le verrou « première régularisation » sans changer
        // le reste à payer (2 500 001 - 1 = 2 500 000), pour isoler le comportement du seuil.
        $facture = $this->makeFacture(2_500_001, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $this->assertFalse($this->service->evaluer($this->org->id, $vehicule->id, null)['blocked']);
    }

    // ── Facture partiellement encaissée : seul le reste à payer compte ──────────

    public function test_facture_partiellement_encaissee_ne_compte_que_pour_son_reste_a_payer(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(500_000, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 450_000);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        // Reste à payer = 500 000 - 450 000 = 50 000, sous le seuil de 100 000.
        $this->assertSame(50_000, $resultat['total_remaining']);
        $this->assertFalse($resultat['blocked']);
    }

    // ── Contrôle global désactivé ────────────────────────────────────────────────

    /**
     * Contrôle de SEUIL désactivé : une dette énorme mais déjà partiellement régularisée (au
     * moins un encaissement, cf. verrou « première régularisation » testé à part) ne bloque
     * jamais. Une dette totalement vierge de tout encaissement, elle, reste bloquée même
     * contrôle désactivé — cf. test_facture_zero_encaissement_bloque_meme_controle_desactive().
     */
    public function test_controle_desactive_ne_bloque_jamais_une_dette_deja_partiellement_regularisee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(50_000_000, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['controle_actif']);
        $this->assertFalse($resultat['blocked']);
    }

    // ── Client sans véhicule (repli) ─────────────────────────────────────────────

    public function test_client_sans_vehicule_utilise_le_seuil_global_et_sa_propre_dette(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $this->makeFacture(150_000, StatutFactureVente::IMPAYEE, null, $client->id);

        $resultat = $this->service->evaluer($this->org->id, null, $client->id);

        $this->assertSame('client', $resultat['cible']);
        $this->assertTrue($resultat['blocked']);
    }

    /**
     * Un véhicule d'un même propriétaire n'entre jamais dans le calcul d'un autre — la dette
     * n'est jamais consolidée par propriétaire (cf. analyse du 18/08/2026), même dérogation
     * comprise : chaque véhicule est totalement indépendant.
     */
    public function test_deux_vehicules_du_meme_proprietaire_ont_des_dettes_independantes(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehiculeA = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $vehiculeB = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'derogation_impayes_autorisee' => true,
            'seuil_derogation_impayes' => 5_000_000,
        ]);

        $this->makeFacture(3_000_000, StatutFactureVente::IMPAYEE, $vehiculeA->id);

        $resultatA = $this->service->evaluer($this->org->id, $vehiculeA->id, null);
        $resultatB = $this->service->evaluer($this->org->id, $vehiculeB->id, null);

        $this->assertTrue($resultatA['blocked'], 'véhicule A a sa propre dette au-delà du seuil global 0');
        $this->assertFalse($resultatB['blocked'], 'véhicule B n\'a aucune facture : la dette de A ne lui est jamais imputée');
    }

    /**
     * Véhicule ET client renseignés simultanément (formulaire de vente, champs indépendants) :
     * seul le véhicule compte, le client n'est jamais consulté — cf. règle "véhicule
     * prioritaire, client en repli" validée le 18/08/2026.
     */
    public function test_vehicule_et_client_simultanes_seul_le_vehicule_compte(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(); // aucune dette
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $this->makeFacture(9_999_999, StatutFactureVente::IMPAYEE, null, $client->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, $client->id);

        $this->assertSame('vehicule', $resultat['cible']);
        $this->assertFalse($resultat['blocked'], 'la dette du client ne doit jamais bloquer quand un véhicule est renseigné');
    }

    // ── enforcerOuEchouer() ───────────────────────────────────────────────────────

    public function test_enforcer_ou_echouer_leve_une_validation_exception_quand_bloque(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(10_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $this->expectException(ValidationException::class);
        $this->service->enforcerOuEchouer($this->org->id, $vehicule->id, null);
    }

    public function test_enforcer_ou_echouer_ne_leve_rien_quand_autorise(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();

        $resultat = $this->service->enforcerOuEchouer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocked']);
    }

    // ── Cohérence avec les statuts de facture ne comptant jamais dans le calcul de SEUIL ────

    public function test_facture_payee_najoute_jamais_a_la_dette(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(1_000_000, StatutFactureVente::PAYEE, $vehicule->id);
        $this->encaisser($facture, 1_000_000);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(0, $resultat['total_remaining']);
        $this->assertFalse($resultat['blocked']);
        $this->assertFalse($resultat['blocage_premiere_facture']);
    }

    // ── Verrou « première régularisation » (décision produit du 20/08/2026) ─────────────────
    // Un véhicule dont une facture n'a reçu AUCUN encaissement ne peut recevoir aucune nouvelle
    // commande — indépendamment du calcul de dette/seuil ci-dessus et du paramètre « contrôle
    // des impayés ». Voir docblock de classe et premiereFactureNonEncaisseeVehicule().

    public function test_vehicule_sans_aucune_facture_nest_jamais_bloque_par_le_verrou(): void
    {
        $vehicule = $this->makeVehicule();

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture']);
        $this->assertNull($resultat['facture_bloquante_reference']);
        $this->assertFalse($resultat['blocked']);
    }

    public function test_facture_impayee_zero_encaissement_bloque_le_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000_000);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(10_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertTrue($resultat['blocage_premiere_facture'], 'un seuil énorme ne doit jamais neutraliser ce verrou');
        $this->assertTrue($resultat['blocked']);
        $this->assertSame($facture->reference, $resultat['facture_bloquante_reference']);
    }

    /**
     * Une facture encore en statut CREEE (commande confirmée, chargement pas encore validé) n'a
     * reçu aucun encaissement au même titre qu'une facture IMPAYEE — elle doit bloquer aussi,
     * même si elle n'entre pas dans le calcul de dette au sens du seuil (cf.
     * test_facture_payee_najoute_jamais_a_la_dette ci-dessus pour ce dernier point). Le verrou
     * est volontairement basé sur montant_encaisse/montant_restant, jamais sur le libellé du
     * statut (cf. docblock de classe).
     */
    public function test_facture_creee_non_encaissee_bloque_le_vehicule_meme_sans_dette_au_sens_du_seuil(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(1_000_000, StatutFactureVente::CREEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        // Le calcul de SEUIL reste inchangé (CREEE toujours exclu, cf. facturesImpayeesVehicule) :
        $this->assertSame(0, $resultat['total_remaining']);
        // ... mais le nouveau verrou absolu, lui, se déclenche bel et bien.
        $this->assertTrue($resultat['blocage_premiere_facture']);
        $this->assertTrue($resultat['blocked']);
        $this->assertSame($facture->reference, $resultat['facture_bloquante_reference']);
    }

    public function test_facture_zero_encaissement_bloque_meme_controle_impayes_desactive(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(10_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['controle_actif'], 'le contrôle de seuil est bien désactivé');
        $this->assertTrue($resultat['blocage_premiere_facture'], 'le verrou absolu ne dépend jamais de ce paramètre');
        $this->assertTrue($resultat['blocked']);
    }

    public function test_facture_partiellement_encaissee_leve_le_verrou_meme_controle_impayes_desactive(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(10_000, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 1);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture']);
        $this->assertFalse($resultat['blocked']);
    }

    public function test_facture_partiellement_encaissee_avec_seuil_depasse_reste_bloquee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(500_000, StatutFactureVente::PARTIEL, $vehicule->id);
        $this->encaisser($facture, 50_000); // reste à payer 450 000 > seuil 100 000

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture'], 'ce n\'est plus le verrou absolu qui bloque ici');
        $this->assertTrue($resultat['blocked'], 'mais bien le contrôle de seuil habituel, qui reprend la main');
    }

    public function test_facture_totalement_payee_ne_bloque_jamais(): void
    {
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(300_000, StatutFactureVente::PAYEE, $vehicule->id);
        $this->encaisser($facture, 300_000);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture']);
        $this->assertFalse($resultat['blocked']);
    }

    /** Une facture ANNULEE n'engendre plus aucune créance réelle — jamais bloquante. */
    public function test_facture_annulee_ne_bloque_jamais(): void
    {
        $vehicule = $this->makeVehicule();
        $this->makeFacture(1_000_000, StatutFactureVente::ANNULEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture']);
        $this->assertFalse($resultat['blocked']);
    }

    /**
     * Plusieurs factures précédentes pour le même véhicule : une déjà soldée ne masque jamais
     * une autre non encaissée — le verrou se déclenche dès qu'UNE SEULE facture non annulée n'a
     * reçu aucun encaissement.
     */
    public function test_plusieurs_factures_une_payee_une_non_encaissee_bloque_a_cause_de_la_non_encaissee(): void
    {
        $vehicule = $this->makeVehicule();
        $payee = $this->makeFacture(200_000, StatutFactureVente::PAYEE, $vehicule->id);
        $this->encaisser($payee, 200_000);
        $nonEncaissee = $this->makeFacture(50_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertTrue($resultat['blocage_premiere_facture']);
        $this->assertSame($nonEncaissee->reference, $resultat['facture_bloquante_reference']);
    }

    /** Isolation multi-organisation : une facture non encaissée d'une autre org n'affecte jamais ce contrôle. */
    public function test_facture_non_encaissee_dune_autre_organisation_ne_bloque_jamais(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre dépôt', 'type' => 'depot']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);
        $vehiculeAutreOrg = Vehicule::factory()->create([
            'organization_id' => $autreOrg->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'vehicule_id' => $vehiculeAutreOrg->id,
        ]);
        FactureVente::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'vehicule_id' => $vehiculeAutreOrg->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 1_000_000,
            'montant_net' => 1_000_000,
            'statut_facture' => StatutFactureVente::IMPAYEE->value,
        ]);

        // Interrogé avec l'organisation A (celle de ce test) alors que le véhicule et sa facture
        // appartiennent à l'organisation B : le filtre organization_id doit exclure cette
        // facture, jamais la laisser fuiter vers une autre organisation.
        $resultat = $this->service->evaluer($this->org->id, $vehiculeAutreOrg->id, null);

        $this->assertFalse($resultat['blocage_premiere_facture']);
        $this->assertFalse($resultat['blocked']);
    }

    // ── enforcerOuEchouer() : message dédié au verrou ────────────────────────────────────────

    public function test_enforcer_ou_echouer_leve_le_message_dedie_a_la_premiere_regularisation(): void
    {
        $vehicule = $this->makeVehicule();
        $facture = $this->makeFacture(10_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        try {
            $this->service->enforcerOuEchouer($this->org->id, $vehicule->id, null);
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('aucun paiement', $e->errors()['impayes'][0]);
            $this->assertStringContainsString($facture->reference, $e->errors()['impayes'][0]);
        }
    }

    /**
     * Un plafond dérogatoire très élevé ne doit jamais permettre de contourner le verrou
     * absolu « première régularisation » (cf. docblock de classe et section métier « Point à
     * confirmer » de l'analyse du 22/08/2026) — ce verrou est totalement indépendant du calcul
     * de seuil/dérogation.
     */
    public function test_derogation_avec_plafond_enorme_ne_contourne_jamais_le_verrou_premiere_regularisation(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 999_999_999]);
        $facture = $this->makeFacture(10_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertTrue($resultat['blocage_premiere_facture']);
        $this->assertTrue($resultat['blocked']);
        $this->assertSame($facture->reference, $resultat['facture_bloquante_reference']);
    }

    // ── Le plafond ne compare JAMAIS la dette existante au montant d'une nouvelle vente
    // (décision produit du 22/08/2026, EN CORRECTION d'une tentative d'« exposition projetée »
    // testée le même jour puis abandonnée — cf. docblock de classe) ───────────────────────────

    /**
     * Régression ABDOULAYE (22/08/2026) : un véhicule en dérogation SANS aucune dette existante
     * ne doit jamais être bloqué, quelle que soit la taille de sa toute première vente — le
     * plafond borne l'encours d'impayés déjà accumulé, jamais la taille d'une transaction.
     * evaluer() n'accepte d'ailleurs plus aucun montant de vente en cours : seule la dette déjà
     * existante entre dans le calcul (cas réel : plafond 500 000 GNF, vente de 10 800 000 GNF
     * refusée à tort avant cette correction).
     */
    public function test_vehicule_en_derogation_sans_dette_nest_jamais_bloque_quel_que_soit_le_plafond(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 500_000]);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(0, $resultat['total_remaining']);
        $this->assertSame('derogation', $resultat['seuil_origine']);
        $this->assertFalse($resultat['blocked'], 'aucune dette existante → jamais bloqué, quel que soit le plafond');
    }
}
