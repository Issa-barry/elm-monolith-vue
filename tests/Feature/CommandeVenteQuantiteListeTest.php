<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\ProduitVariante;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Colonne « Qté » des pages Ventes et Factures : total des unités de la commande, calculé une
 * seule fois côté serveur (CommandeVente::quantite_totale), jamais recalculé côté Vue.
 */
class CommandeVenteQuantiteListeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private ProduitVariante $varianteA;

    private ProduitVariante $varianteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);

        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
        // Deux variantes distinctes : une commande ne peut porter qu'une ligne par variante.
        $this->varianteA = $this->makeProduitAvecVariante($this->org)->variantes()->firstOrFail();
        $this->varianteB = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit B'])->variantes()->firstOrFail();
    }

    private function makeCommande(): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 5000,
            'reference' => 'QTE-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ]);
    }

    private function addLigne(CommandeVente $commande, ProduitVariante $variante, int $demandee, ?int $chargee = null, ?int $livree = null): void
    {
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $demandee,
            'quantite_chargee' => $chargee,
            'quantite_livree' => $livree,
            'libelle_snapshot' => 'Pack Eau 1.5L',
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 0,
        ]);
    }

    public function test_ventes_index_expose_la_quantite_totale_de_chaque_commande(): void
    {
        $commande = $this->makeCommande();
        $this->addLigne($commande, $this->varianteA, 10, 8);
        $this->addLigne($commande, $this->varianteB, 5, 5, 4);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['numero_commande' => $commande->reference]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.quantite_totale', 12),
            );
    }

    public function test_ventes_index_commande_sans_ligne_a_une_quantite_nulle(): void
    {
        $commande = $this->makeCommande();

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['numero_commande' => $commande->reference]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.quantite_totale', 0),
            );
    }

    public function test_factures_index_expose_la_quantite_totale_de_la_commande_facturee(): void
    {
        $commande = $this->makeCommande();
        $this->addLigne($commande, $this->varianteA, 10, 8);
        $this->addLigne($commande, $this->varianteB, 5, 5, 4);

        $facture = FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
            'statut_facture' => 'impayee',
        ]);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'reference' => $facture->reference]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('factures', 1)
                ->where('factures.0.quantite_totale', 12),
            );
    }

    public function test_factures_index_commande_sans_ligne_a_une_quantite_nulle(): void
    {
        $commande = $this->makeCommande();

        $facture = FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
            'statut_facture' => 'impayee',
        ]);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'reference' => $facture->reference]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('factures', 1)
                ->where('factures.0.quantite_totale', 0),
            );
    }
}
