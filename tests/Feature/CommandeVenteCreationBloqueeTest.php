<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Le bouton « Nouvelle commande » de la page Ventes (et l'accès direct à sa route) est bloqué
 * uniquement quand la politique globale interdit la vente sans stock (Parametre::
 * isVentesAutoriseesSansStock()) ET que le site personnel de l'utilisateur (celui que
 * CommandeVenteController::getUserSiteModel() utilisera réellement pour la commande) n'a
 * absolument aucun stock vendable — cf. CommandeVenteService::siteAutoriseNouvelleCommande().
 * Le blocage vit aussi bien dans les props Inertia (état du bouton) que dans create()/store()
 * (accès direct par URL non contournable) — pas seulement côté frontend.
 */
class CommandeVenteCreationBloqueeTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

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

        // Type explicitement vendable ('fabricable') : le type par défaut de
        // makeProduitAvecVariante() ('materiel') a gere_stock=true MAIS vendable=false — jamais
        // proposé à la vente (cf. CommandeVenteController::produitsActifs(), qui filtre déjà
        // vendable=true) et donc jamais compté par stockTotalVendableSite().
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau', 'type' => 'fabricable'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->produit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    // ── Props Inertia (état du bouton) ───────────────────────────────────────

    public function test_bouton_bloque_si_politique_off_et_stock_site_a_zero(): void
    {
        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page
                ->where('can_creer_commande', false)
                ->where('raison_blocage_commande', 'Impossible de créer une commande : aucun stock disponible pour ce site.')
            );
    }

    public function test_bouton_actif_si_politique_off_et_stock_site_positif(): void
    {
        $this->seedStock(10);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page
                ->where('can_creer_commande', true)
                ->where('raison_blocage_commande', null)
            );
    }

    public function test_bouton_actif_si_politique_on_meme_stock_site_a_zero(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', true));
    }

    public function test_bouton_actif_si_stock_site_negatif_mais_non_nul(): void
    {
        // Politique désactivée, mais le stock du site n'est pas exactement 0 (déjà négatif
        // suite à une politique précédemment activée) — le garde-fou est "total = 0", pas
        // "total <= 0" : seul un site VRAIMENT vide bloque le bouton.
        $this->seedStock(-5);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', true));
    }

    // ── Accès direct à la route (contournement du bouton) ────────────────────

    public function test_acces_direct_a_create_est_refuse_si_bloque(): void
    {
        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertForbidden();
    }

    public function test_acces_direct_a_create_fonctionne_si_stock_suffisant(): void
    {
        $this->seedStock(10);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertOk();
    }

    public function test_post_store_direct_est_refuse_si_bloque(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $this->actingAs($this->user)
            ->post('/backoffice/ventes', [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $this->produit->id, 'qte' => 1, 'prix_vente' => 2000],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('commandes_ventes', 0);
    }

    public function test_post_store_fonctionne_si_politique_on_meme_stock_a_zero(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $this->actingAs($this->user)
            ->post('/backoffice/ventes', [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $this->produit->id, 'qte' => 1, 'prix_vente' => 2000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('commandes_ventes', 1);
    }

    // ── Isolation multi-organisation / multi-site ────────────────────────────

    public function test_isolation_le_blocage_dune_organisation_naffecte_pas_une_autre(): void
    {
        $orgB = Organization::factory()->create();
        $userB = $this->makeUserWithPermissions($orgB, ['ventes.read', 'ventes.create', 'ventes.update']);
        $siteB = Site::create([
            'organization_id' => $orgB->id,
            'nom' => 'Site B',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $userB->sites()->attach($siteB->id, ['role' => 'employe', 'is_default' => true]);
        $produitB = $this->makeProduitAvecVariante($orgB, ['nom' => 'Pack B', 'type' => 'fabricable'], ['prix_vente' => 2000, 'prix_usine' => 1500]);
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteB->id,
            'qte_stock' => 50,
        ]);

        // Org A : aucun stock → bloqué.
        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', false));

        // Org B : stock positif → jamais affectée par l'état de l'organisation A.
        $this->actingAs($userB)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', true));
    }

    /** Un produit non vendable (achetable uniquement) ne doit jamais débloquer le bouton. */
    public function test_stock_dun_produit_non_vendable_ne_compte_pas(): void
    {
        $produitNonVendable = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Matière première', 'produit_type_id' => $this->produitTypeIdMatiereProduction()],
        );
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produitNonVendable->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 1000,
        ]);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', false));
    }

    private function produitTypeIdMatiereProduction(): string
    {
        \Database\Seeders\ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        return \App\Models\ProduitType::where('organization_id', $this->org->id)
            ->where('code', 'matiere_production')
            ->value('id');
    }
}
