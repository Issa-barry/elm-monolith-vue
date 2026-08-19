<?php

namespace Tests\Feature;

use App\Enums\StatutFactureVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
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
        $this->makeDette(50_000, $vehicule->id);
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
     * Le seuil spécifique du véhicule (dérogation) doit réellement s'appliquer à la création
     * réelle, pas seulement à l'aperçu — cf. Vehicule::seuil_dette_derogation.
     */
    public function test_commande_autorisee_grace_au_seuil_specifique_du_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 0);
        $vehicule = $this->makeVehicule(['seuil_dette_derogation' => 2_000_000]);
        $this->makeDette(1_500_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');

        $this->assertSame(2, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_commande_bloquee_au_dela_du_seuil_specifique_du_vehicule(): void
    {
        Parametre::setVentesControleImpayes($this->org->id, true, 10_000_000);
        $vehicule = $this->makeVehicule(['seuil_dette_derogation' => 2_000_000]);
        $this->makeDette(2_500_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
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
        $this->makeDette(50_000_000, $vehicule->id);
        $produit = $this->makeProduitAvecVariante($this->org, ['categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5000]],
            ])
            ->assertSessionDoesntHaveErrors('impayes');
    }
}
