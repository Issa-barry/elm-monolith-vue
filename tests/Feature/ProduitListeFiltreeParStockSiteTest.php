<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Quand la politique globale interdit la vente sans stock (Parametre::
 * isVentesAutoriseesSansStock() = false, décision produit du 24/08/2026), la liste de
 * produits proposée à la vente (page Ventes > Nouvelle commande, et grille PDV) ne doit
 * afficher que les produits/variantes dont le stock est STRICTEMENT positif sur le site
 * courant — jamais sur l'agrégat global du produit (cf. CommandeVenteController::
 * produitsActifs(), PdvController::produitsPdv()). Quand la politique autorise la vente sans
 * stock, la liste reste complète, y compris les produits à 0.
 */
class ProduitListeFiltreeParStockSiteTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Site $autreSite;

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
        $this->autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->user->sites()->attach($this->autreSite->id, ['role' => 'employe']);
    }

    private function makeProduitVendable(string $nom): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => $nom, 'type' => 'fabricable'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function seedStock(Produit $produit, Site $site, int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $produit->variantePrincipale()->first()->id, 'site_id' => $site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    // ── Page Ventes > Nouvelle commande ──────────────────────────────────────

    public function test_create_masque_un_produit_a_stock_zero_sur_le_site_quand_politique_off(): void
    {
        $avecStock = $this->makeProduitVendable('Avec stock');
        $this->seedStock($avecStock, $this->site, 10);
        $sansStock = $this->makeProduitVendable('Sans stock');
        $this->seedStock($sansStock, $this->site, 0);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.nom', 'Avec stock')
            );
    }

    public function test_create_masque_un_produit_dont_le_stock_est_uniquement_sur_un_autre_site(): void
    {
        // Un produit « témoin » avec du stock sur le site courant : sans lui, le site aurait un
        // total vendable de 0 et la page serait bloquée en entier (cf. redirectSiCreationBloquee()
        // / siteAutoriseNouvelleCommande()) — ce test vérifie le FILTRAGE d'un produit précis
        // dans une liste par ailleurs non vide, pas le blocage global de la page (couvert dans
        // CommandeVenteCreationBloqueeTest).
        $temoin = $this->makeProduitVendable('Témoin');
        $this->seedStock($temoin, $this->site, 5);

        $produit = $this->makeProduitVendable('Pack Eau');
        // Stock positif, mais sur l'AUTRE site — jamais sur le site courant de l'utilisateur.
        $this->seedStock($produit, $this->autreSite, 500);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.nom', 'Témoin')
            );
    }

    public function test_create_affiche_tous_les_produits_meme_a_zero_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $sansStock = $this->makeProduitVendable('Sans stock');
        $this->seedStock($sansStock, $this->site, 0);

        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.nom', 'Sans stock')
            );
    }

    /**
     * Aucun type par défaut n'est à la fois vendable=true et gere_stock=false (cf.
     * ProduitTypeDefaultSeeder) — un type personnalisé de ce genre reste possible via le CRUD
     * Types. Un tel produit ne doit jamais être filtré par le stock, faute de stock à
     * comparer : le garde-fou `$gereStock` dans produitsActifs()/produitsPdv() doit le laisser
     * toujours visible, même sans aucune ligne VarianteStock.
     */
    public function test_create_najamais_ne_filtre_un_produit_qui_ne_gere_pas_de_stock(): void
    {
        // Témoin avec stock (cf. commentaire du test précédent) : sans lui, stockTotalVendableSite()
        // resterait à 0 (le produit sans gere_stock n'y contribue jamais) et la page serait
        // bloquée en entier avant même d'atteindre le filtrage testé ici.
        $temoin = $this->makeProduitVendable('Témoin');
        $this->seedStock($temoin, $this->site, 5);

        $typeVendableSansStock = ProduitType::create([
            'organization_id' => $this->org->id,
            'nom' => 'Prestation',
            'code' => 'prestation',
            'gere_stock' => false,
            'vendable' => true,
            'achetable' => false,
            'statut' => 'actif',
        ]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Livraison express', 'produit_type_id' => $typeVendableSansStock->id],
            ['prix_vente' => 5000],
        );
        // Aucune ligne VarianteStock pour ce produit sur aucun site.

        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 2)
                ->where('produits.0.nom', 'Livraison express')
            );
    }

    // ── PDV ───────────────────────────────────────────────────────────────────

    public function test_pdv_masque_un_produit_a_stock_zero_sur_le_site_quand_politique_off(): void
    {
        $avecStock = $this->makeProduitVendable('Avec stock');
        $this->seedStock($avecStock, $this->site, 10);
        $sansStock = $this->makeProduitVendable('Sans stock');
        $this->seedStock($sansStock, $this->site, 0);

        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.name', 'Avec stock')
                ->where('produits.0.stock', 10)
            );
    }

    public function test_pdv_masque_un_produit_dont_le_stock_est_uniquement_sur_un_autre_site(): void
    {
        $produit = $this->makeProduitVendable('Pack Eau');
        $this->seedStock($produit, $this->autreSite, 500);

        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertInertia(fn ($page) => $page->has('produits', 0));
    }

    public function test_pdv_affiche_tous_les_produits_meme_a_zero_quand_politique_on(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $sansStock = $this->makeProduitVendable('Sans stock');
        $this->seedStock($sansStock, $this->site, 0);

        $this->actingAs($this->user)
            ->get('/backoffice/pdv')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.stock', 0)
            );
    }

    // ── Isolation multi-organisation ─────────────────────────────────────────

    public function test_isolation_la_politique_dune_organisation_naffecte_pas_le_filtrage_dune_autre(): void
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
        Parametre::setVentesAutoriserStockNegatif($orgB->id, true);

        $produitB = $this->makeProduitAvecVariante(
            $orgB,
            ['nom' => 'Pack B', 'type' => 'fabricable'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        VarianteStock::create([
            'organization_id' => $orgB->id,
            'produit_variante_id' => $produitB->variantePrincipale()->first()->id,
            'site_id' => $siteB->id,
            'qte_stock' => 0,
        ]);

        // Témoin avec stock : sans lui, le site A aurait un total vendable de 0 et la page de
        // création serait bloquée en entier plutôt que de rendre une liste filtrée.
        $temoin = $this->makeProduitVendable('Témoin A');
        $this->seedStock($temoin, $this->site, 5);

        // Org A (politique OFF, jamais configurée) : produit à 0 → masqué.
        $sansStockA = $this->makeProduitVendable('Sans stock A');
        $this->seedStock($sansStockA, $this->site, 0);
        $this->actingAs($this->user)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page
                ->has('produits', 1)
                ->where('produits.0.nom', 'Témoin A')
            );

        // Org B (politique ON) : produit à 0 → visible, jamais affecté par la politique de A.
        $this->actingAs($userB)
            ->get('/backoffice/ventes/create')
            ->assertInertia(fn ($page) => $page->has('produits', 1));
    }
}
