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

    private function makeType(?int $seuilDerogation = null): TypeVehicule
    {
        return TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'seuil_derogation_impayes' => $seuilDerogation,
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
        $this->makeFacture(500_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame('vehicule', $resultat['cible']);
        $this->assertSame(500_000, $resultat['seuil_impayes']);
        $this->assertFalse($resultat['blocked'], 'dette = seuil → autorisé, jamais bloqué à l\'égalité');
    }

    // ── Dérogation via le type de véhicule ───────────────────────────────────────

    public function test_vehicule_avec_derogation_utilise_le_seuil_de_son_type(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = $this->makeType(2_000_000);
        $vehicule = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);
        $this->makeFacture(1_500_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(2_000_000, $resultat['seuil_impayes']);
        $this->assertFalse($resultat['blocked'], 'sous le seuil dérogatoire du type → autorisé');
        $this->assertSame(500_000, $resultat['montant_disponible']);
    }

    public function test_vehicule_avec_derogation_bloque_au_dela_du_seuil_de_son_type(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 10_000_000);
        $type = $this->makeType(2_000_000);
        $vehicule = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);
        $this->makeFacture(2_000_001, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertTrue($resultat['blocked'], 'le seuil du type prime sur un seuil global bien plus large');
        $this->assertSame(1, $resultat['depassement']);
    }

    /**
     * Un type de camion et un type de tricycle avec des seuils dérogatoires différents doivent
     * réellement produire des plafonds différents — pas de valeur globale unique partagée.
     */
    public function test_deux_types_de_vehicule_ont_des_seuils_derogatoires_independants(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $tricycle = $this->makeType(2_000_000);
        $camion = $this->makeType(10_000_000);
        $vehiculeTricycle = $this->makeVehicule(['type_vehicule_id' => $tricycle->id, 'derogation_impayes_autorisee' => true]);
        $vehiculeCamion = $this->makeVehicule(['type_vehicule_id' => $camion->id, 'derogation_impayes_autorisee' => true]);

        $this->assertSame(2_000_000, $this->service->evaluer($this->org->id, $vehiculeTricycle->id, null)['seuil_impayes']);
        $this->assertSame(10_000_000, $this->service->evaluer($this->org->id, $vehiculeCamion->id, null)['seuil_impayes']);
    }

    /**
     * Filet de sécurité : un véhicule dérogatoire dont le type n'a PAS de seuil configuré ne
     * doit jamais être traité comme illimité — retombe sur le seuil global. Ce cas est
     * normalement empêché côté formulaire (VehiculeController::ensureDerogationCoherente()),
     * mais le service doit rester sûr même si le seuil du type est retiré après coup.
     */
    public function test_vehicule_avec_derogation_mais_type_sans_seuil_configure_retombe_sur_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 500_000);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true]);
        $this->makeFacture(600_000, StatutFactureVente::IMPAYEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(500_000, $resultat['seuil_impayes'], 'type non configuré → filet de sécurité, jamais illimité');
        $this->assertTrue($resultat['blocked']);
    }

    /** Même type, deux véhicules : la dérogation reste individuelle, pas automatique pour tout le type. */
    public function test_deux_vehicules_du_meme_type_un_avec_derogation_lautre_sans(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = $this->makeType(3_000_000);
        $avecDerogation = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);
        $sansDerogation = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => false]);
        $this->makeFacture(1_000_000, StatutFactureVente::IMPAYEE, $avecDerogation->id);
        $this->makeFacture(1_000_000, StatutFactureVente::IMPAYEE, $sansDerogation->id);

        $this->assertFalse($this->service->evaluer($this->org->id, $avecDerogation->id, null)['blocked']);
        $this->assertTrue($this->service->evaluer($this->org->id, $sansDerogation->id, null)['blocked']);
    }

    /** Changer le type d'un véhicule dérogatoire fait automatiquement suivre son plafond. */
    public function test_changement_de_type_change_le_seuil_applicable_automatiquement(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $tricycle = $this->makeType(2_000_000);
        $camion = $this->makeType(10_000_000);
        $vehicule = $this->makeVehicule(['type_vehicule_id' => $tricycle->id, 'derogation_impayes_autorisee' => true]);

        $this->assertSame(2_000_000, $this->service->evaluer($this->org->id, $vehicule->id, null)['seuil_impayes']);

        $vehicule->update(['type_vehicule_id' => $camion->id]);

        $this->assertSame(10_000_000, $this->service->evaluer($this->org->id, $vehicule->id, null)['seuil_impayes']);
    }

    /** Modifier le seuil du type impacte tous ses véhicules dérogatoires, sans rien toucher véhicule par véhicule. */
    public function test_modification_du_seuil_du_type_impacte_tous_ses_vehicules_derogatoires(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = $this->makeType(2_000_000);
        $vehiculeA = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);
        $vehiculeB = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);

        $type->update(['seuil_derogation_impayes' => 3_000_000]);

        $this->assertSame(3_000_000, $this->service->evaluer($this->org->id, $vehiculeA->id, null)['seuil_impayes']);
        $this->assertSame(3_000_000, $this->service->evaluer($this->org->id, $vehiculeB->id, null)['seuil_impayes']);
    }

    // ── Comportement strict du seuil : dette = seuil autorisé, dette > seuil bloqué ─

    public function test_dette_egale_au_seuil_est_autorisee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 2_000_000);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(2_000_000, StatutFactureVente::IMPAYEE, $vehicule->id);

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

    /** Seuil standard à 0 mais dérogation du type au-dessus : le véhicule dérogatoire n'est pas bloqué. */
    public function test_seuil_standard_zero_avec_derogation_du_type_au_dessus(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = $this->makeType(3_000_000);
        $vehicule = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true]);
        $this->makeFacture(2_500_000, StatutFactureVente::IMPAYEE, $vehicule->id);

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

    public function test_controle_desactive_ne_bloque_jamais_meme_avec_une_dette_enorme(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(50_000_000, StatutFactureVente::IMPAYEE, $vehicule->id);

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
        $type = $this->makeType(5_000_000);
        $vehiculeA = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $vehiculeB = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule_id' => $type->id,
            'derogation_impayes_autorisee' => true,
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

    // ── Cohérence avec les statuts de facture ne comptant jamais ────────────────

    public function test_facture_payee_ou_creee_najoute_jamais_a_la_dette(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $this->makeFacture(1_000_000, StatutFactureVente::PAYEE, $vehicule->id);
        $this->makeFacture(1_000_000, StatutFactureVente::CREEE, $vehicule->id);

        $resultat = $this->service->evaluer($this->org->id, $vehicule->id, null);

        $this->assertSame(0, $resultat['total_remaining']);
        $this->assertFalse($resultat['blocked']);
    }
}
