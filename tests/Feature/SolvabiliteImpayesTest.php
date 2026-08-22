<?php

namespace Tests\Feature;

use App\Enums\StatutFactureVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Contrôle des impayés bout en bout (back-office) — sur SolvabiliteService, seule source de
 * vérité (cf. son docblock). Le PDV est couvert séparément dans PdvCheckoutTest (même service,
 * appelant distinct). Les scénarios de calcul pur (seuils, arrondis, statuts de facture) sont
 * couverts par tests/Unit/SolvabiliteServiceTest — ce fichier vérifie que le contrôleur
 * applique bien cette règle à la création réelle d'une commande, et que l'aperçu
 * (check-solvabilite) reste cohérent avec ce que la confirmation applique réellement.
 */
class SolvabiliteImpayesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create']);
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Défaut']);
    }

    private function site(): Site
    {
        return $this->user->sites()->firstOrFail();
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            ...$overrides,
        ]);
        // Pas de limite de chargement pour ces tests — seul le contrôle d'impayés est visé.
        $vehicule->capacites()->create([
            'organization_id' => $this->org->id,
            'categorie_id' => $this->categorie->id,
            'capacite_max' => 99999,
        ]);

        return $vehicule;
    }

    /** Facture IMPAYEE rattachée à un véhicule et/ou un client, via sa commande. */
    private function makeDette(int $montant, ?string $vehiculeId = null, ?string $clientId = null): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site()->id,
            'vehicule_id' => $vehiculeId,
            'client_id' => $clientId,
        ]);

        return FactureVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site()->id,
            'vehicule_id' => $vehiculeId,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => StatutFactureVente::IMPAYEE->value,
        ]);
    }

    /**
     * Dette PARTIELLEMENT réglée (au moins un encaissement) — à utiliser pour tester le contrôle
     * de SEUIL en isolation du nouveau verrou « première régularisation » (cf.
     * SolvabiliteService), qui bloquerait sinon inconditionnellement toute facture à 0 encaissé.
     */
    private function makeDettePartielle(int $montant, int $encaisse, ?string $vehiculeId = null, ?string $clientId = null): FactureVente
    {
        $facture = $this->makeDette($montant, $vehiculeId, $clientId);
        $facture->update(['statut_facture' => StatutFactureVente::PARTIEL->value]);

        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $encaisse,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        return $facture;
    }

    // ── Défaut d'une organisation neuve (aucun Parametre enregistré) ────────────

    /**
     * Aucun appel à Parametre::set...() dans ce test : org totalement neuve, comme au premier
     * jour d'installation — le contrôle doit être actif et le seuil à 0 par défaut (décision
     * produit du 18/08/2026), sans quoi cette commande serait acceptée à tort.
     */
    public function test_organisation_neuve_bloque_par_defaut_sur_la_moindre_dette(): void
    {
        $vehicule = $this->makeVehicule();
        $this->makeDette(1, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');

        // makeDette() crée déjà une commande pour ce véhicule (celle qui porte la facture
        // impayée) : seule la nouvelle tentative de vente doit avoir échoué, count() reste à 1.
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    // ── Back-office : véhicule ──────────────────────────────────────────────────

    public function test_commande_bloquee_quand_dette_vehicule_depasse_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $this->makeDette(150_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');

        // Une seule commande pour ce véhicule : celle de la dette (makeDette()) — la nouvelle
        // vente n'a jamais été créée.
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_commande_autorisee_quand_dette_vehicule_sous_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        // Dette PARTIELLEMENT réglée : isole le contrôle de seuil du nouveau verrou « première
        // régularisation » (testé séparément, cf. VehiculePremiereRegularisationTest), qui
        // bloquerait sinon inconditionnellement une facture à 0 encaissé.
        $this->makeDettePartielle(50_000, 10_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        // La commande de la dette + la nouvelle vente autorisée = 2.
        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /**
     * Régression ABDOULAYE (22/08/2026) : le montant de la nouvelle vente ne doit JAMAIS être
     * ajouté à la dette existante pour décider du blocage, même pour un véhicule en dérogation —
     * une tentative d'« exposition projetée » (dette + nouvelle vente) a été testée le jour même
     * puis abandonnée après avoir bloqué à tort la première vente d'un véhicule parfaitement
     * sain. Le plafond borne l'encours d'impayés déjà accumulé, jamais la taille d'une
     * transaction (cf. SolvabiliteService).
     */
    public function test_commande_autorisee_meme_quand_son_montant_depasse_le_plafond_derogatoire_si_la_dette_existante_reste_sous_le_plafond(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 6_000]);
        // Reste à payer 2 000, sous le plafond de 6 000 — la nouvelle vente (1 x 5000 GNF) ne
        // doit jamais y être ajoutée, même si 2 000 + 5 000 dépasserait 6 000.
        $this->makeDettePartielle(2_010, 10, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /**
     * Régression ABDOULAYE, cas exact reproduit : véhicule EN DÉROGATION sans la moindre dette
     * existante, plafond nettement inférieur au montant de sa toute première vente → autorisée.
     */
    public function test_commande_autorisee_pour_un_vehicule_en_derogation_sans_dette_meme_si_son_montant_depasse_largement_le_plafond(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 500_000]);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 10_800_000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 10_800_000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    /**
     * La dérogation individuelle du véhicule doit réellement s'appliquer à la création réelle,
     * pas seulement à l'aperçu — cf. Vehicule::seuil_derogation_impayes.
     */
    public function test_commande_autorisee_grace_a_la_derogation_du_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        // Dette PARTIELLEMENT réglée : isole le contrôle de seuil dérogatoire du verrou
        // « première régularisation », qui bloquerait sinon inconditionnellement (cf. plus haut).
        $this->makeDettePartielle(1_500_000, 10_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_commande_bloquee_au_dela_du_plafond_derogatoire_du_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 10_000_000);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        $this->makeDette(2_500_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    /**
     * Deux véhicules du MÊME type de véhicule, avec des plafonds dérogatoires différents,
     * doivent réellement se comporter différemment à la création réelle — la dérogation ne
     * dépend jamais du type (cf. décision produit du 22/08/2026).
     */
    public function test_deux_vehicules_du_meme_type_ont_des_comportements_de_blocage_independants(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehiculeLarge = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 2_000_000]);
        $vehiculeEtroit = $this->makeVehicule(['type_vehicule_id' => $type->id, 'derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 100_000]);
        $this->makeDettePartielle(1_500_000, 10_000, $vehiculeLarge->id);
        $this->makeDettePartielle(1_500_000, 10_000, $vehiculeEtroit->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehiculeLarge->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehiculeEtroit->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    // ── Back-office : client en repli (aucun véhicule) ──────────────────────────

    public function test_commande_client_bloquee_quand_sa_dette_depasse_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $this->makeDette(150_000, null, $client->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    public function test_commande_client_autorisee_sous_le_seuil_global(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $this->makeDette(50_000, null, $client->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }

    /**
     * Véhicule ET client renseignés simultanément (les deux champs du formulaire sont
     * indépendants, cf. Ventes/Create.vue) : seul le véhicule compte — la dette écrasante du
     * client ne doit jamais bloquer une commande dont le véhicule est propre.
     */
    public function test_commande_avec_vehicule_et_client_ignore_la_dette_du_client(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule();
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $this->makeDette(9_999_999, null, $client->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }

    // ── Cohérence aperçu (check-solvabilite) / création réelle ──────────────────

    public function test_check_solvabilite_annonce_le_meme_blocage_que_la_creation_reelle(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $this->makeDette(150_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $apercu = $this->actingAs($this->user)
            ->get('/backoffice/ventes/check-solvabilite?vehicule_id='.$vehicule->id)
            ->assertOk()
            ->json();

        $this->assertTrue($apercu['blocked']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionHasErrors('impayes');
    }

    public function test_check_solvabilite_annonce_autorise_quand_la_creation_reelle_lest_aussi(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 100_000);
        $vehicule = $this->makeVehicule();
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $apercu = $this->actingAs($this->user)
            ->get('/backoffice/ventes/check-solvabilite?vehicule_id='.$vehicule->id)
            ->assertOk()
            ->json();

        $this->assertFalse($apercu['blocked']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }

    // ── Régression ABDOULAYE (22/08/2026) : l'aperçu (check-solvabilite) ne bloque jamais un
    // véhicule sans dette existante, même en dérogation avec un plafond très inférieur au
    // montant de la vente en cours de saisie — même décision que la création réelle ───────────

    public function test_check_solvabilite_nannonce_jamais_de_blocage_pour_un_vehicule_en_derogation_sans_dette(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 500_000]);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 10_800_000]);

        $apercu = $this->actingAs($this->user)
            ->get('/backoffice/ventes/check-solvabilite?vehicule_id='.$vehicule->id)
            ->assertOk()
            ->json();

        $this->assertFalse($apercu['blocked']);
        $this->assertSame('derogation', $apercu['seuil_origine']);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 10_800_000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }

    /**
     * `montant_prevu` n'existe plus dans l'API check-solvabilite (retiré avec l'exposition
     * projetée) : un ancien client (cache navigateur, extension, requête forgée) qui
     * continuerait à l'envoyer ne doit avoir AUCUN effet — le paramètre est simplement ignoré.
     */
    public function test_check_solvabilite_ignore_un_parametre_montant_prevu_residuel(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['derogation_impayes_autorisee' => true, 'seuil_derogation_impayes' => 500_000]);

        $apercu = $this->actingAs($this->user)
            ->get('/backoffice/ventes/check-solvabilite?vehicule_id='.$vehicule->id.'&montant_prevu=999999999')
            ->assertOk()
            ->json();

        $this->assertFalse($apercu['blocked']);
    }

    // ── Organisations existantes : valeur explicitement enregistrée respectée ──

    /**
     * Une organisation ayant explicitement désactivé le contrôle (ligne réelle en base) ne doit
     * jamais être réactivée arbitrairement par le nouveau fallback — cf. Parametre::
     * isVentesControleImpayesActif(), dont le fallback ne s'applique qu'en l'absence de ligne.
     */
    public function test_organisation_ayant_explicitement_desactive_le_controle_reste_desactivee(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, false, 0);
        $vehicule = $this->makeVehicule();
        // Dette énorme mais PARTIELLEMENT réglée : isole le contrôle de seuil (désactivé ici) du
        // verrou « première régularisation », indépendant de ce paramètre et qui bloquerait sinon
        // inconditionnellement une facture à 0 encaissé (cf. VehiculePremiereRegularisationTest).
        $this->makeDettePartielle(50_000_000, 1, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }
}
