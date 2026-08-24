<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Le bouton « Nouvelle commande » de la page Ventes (et l'accès direct à sa route) est bloqué
 * uniquement quand la politique globale interdit la vente sans stock (Parametre::
 * isVentesAutoriseesSansStock()) ET que le site personnel de l'utilisateur (celui que
 * CommandeVenteController::getUserSiteModel() utilisera réellement pour la commande) n'a
 * absolument AUCUN produit vendable maintenant — cf. CommandeVenteService::
 * siteAutoriseNouvelleCommande() / StockStatutService::sitePossedeStockVendable(). C'est une
 * EXISTENCE ("le site a-t-il quelque chose à vendre ?"), jamais une somme de quantités : un
 * produit à +5 et un autre à -5 sur le même site reste vendable (décision produit du
 * 24/08/2026), une quantité négative isolée ne l'est jamais, et un produit/service qui ne gère
 * pas de stock physique (type "service") reste toujours vendable, quel que soit le stock du
 * site. Le blocage vit aussi bien dans les props Inertia (état du bouton) que dans
 * create()/store() (accès direct par URL non contournable) — pas seulement côté frontend.
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
                ->where('raison_blocage_commande', 'Aucun stock disponible pour ce site.')
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

    /**
     * Remplace l'ancien test qui affirmait à tort qu'un stock négatif isolé rendait le bouton
     * actif : sitePossedeStockVendable() exige une quantité STRICTEMENT positive quelque part
     * sur le site — une quantité négative (résidu d'une politique précédemment activée, ou
     * d'une vente en négatif) ne rend jamais, à elle seule, un site vendable.
     */
    public function test_bouton_bloque_si_seul_stock_du_site_est_negatif(): void
    {
        $this->seedStock(-5);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page
                ->where('can_creer_commande', false)
                ->where('raison_blocage_commande', 'Aucun stock disponible pour ce site.')
            );
    }

    /**
     * Un produit à +5 ET un autre à -5 sur le même site : le site a bien un produit
     * réellement vendable (celui à +5). Une somme des deux quantités donnerait 0 et
     * masquerait à tort ce produit vendable — exactement le bug corrigé le 24/08/2026 (cf.
     * StockStatutService::sitePossedeStockVendable(), qui n'utilise plus jamais de somme).
     */
    public function test_bouton_actif_si_un_produit_positif_malgre_un_autre_negatif_sur_le_site(): void
    {
        $this->seedStock(5);

        $autreProduit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Négatif', 'type' => 'fabricable'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $autreProduit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => -5],
        );

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', true));
    }

    /**
     * Un produit/service vendable qui NE gère PAS de stock (ex: prestation) rend le bouton
     * actif même si le site n'a absolument aucune ligne de stock physique — $this->produit
     * (fabricable, gere_stock=true) reste à 0 tout au long de ce test : seul le service ici
     * rend le site vendable.
     */
    public function test_bouton_actif_si_un_service_vendable_sans_stock_existe(): void
    {
        $typeService = ProduitType::create([
            'organization_id' => $this->org->id,
            'nom' => 'Prestation',
            'code' => 'prestation',
            'gere_stock' => false,
            'vendable' => true,
            'achetable' => false,
            'statut' => 'actif',
        ]);
        $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Livraison express', 'produit_type_id' => $typeService->id],
            ['prix_vente' => 5000],
        );

        $this->actingAs($this->user)
            ->get('/backoffice/ventes')
            ->assertInertia(fn ($page) => $page->where('can_creer_commande', true));
    }

    // ── Accès direct à la route (contournement du bouton) ────────────────────

    /**
     * Jamais une page 403 : un accès direct malgré le blocage redirige vers la liste des
     * ventes avec un flash 'error', affiché en toast top-right côté Ventes/Index.vue.
     */
    public function test_acces_direct_a_create_est_refuse_si_bloque(): void
    {
        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertRedirect('/backoffice/ventes')
            ->assertSessionHas('error', 'Impossible de créer une commande : aucun stock disponible pour ce site.');
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
            ->assertRedirect('/backoffice/ventes')
            ->assertSessionHas('error', 'Impossible de créer une commande : aucun stock disponible pour ce site.');

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
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        return ProduitType::where('organization_id', $this->org->id)
            ->where('code', 'matiere_production')
            ->value('id');
    }
}
