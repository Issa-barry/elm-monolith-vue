<?php

namespace Tests\Unit;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Organization;
use App\Services\PrixVenteNatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Couvre PrixVenteNatureResolver::resolve() — tarification par nature de client
 * (Externe/Revendeur/Distributeur), réservée aux produits fabricables, indépendante de
 * prix_usine/prix_vente. Cf. rapport du 28/08/2026.
 *
 * Utilise HasProduitVariante (création directe, hors ProduitService) plutôt que
 * ProduitService::creer() : ce dernier exige désormais les 3 tarifs de nature pour tout produit
 * fabricable (cf. ProduitServicePrixTest::test_fabricable_refuse_si_un_prix_par_nature_est_absent),
 * ce qui rendrait impossible de construire ici les scénarios "tarif non configuré" que ce fichier
 * teste précisément (repli du resolver, pertinent pour des variantes historiques antérieures à
 * cette obligation).
 */
class PrixVenteNatureResolverTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    private function client(string $type): Client
    {
        return Client::factory()->create(['organization_id' => $this->org->id, 'type' => $type]);
    }

    public function test_fabricable_avec_client_externe_utilise_prix_externe(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable'],
            [
                'prix_usine' => 18000,
                'prix_vente' => 22000,
                'prix_externe' => 18250,
                'prix_revendeur' => 20000,
                'prix_distributeur' => 18500,
            ],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(18250, PrixVenteNatureResolver::resolve($variante, $this->client(ClientType::EXTERNE->value)));
        $this->assertSame(20000, PrixVenteNatureResolver::resolve($variante, $this->client(ClientType::REVENDEUR->value)));
        $this->assertSame(18500, PrixVenteNatureResolver::resolve($variante, $this->client(ClientType::DISTRIBUTEUR->value)));
    }

    public function test_fabricable_sans_client_utilise_prix_vente(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable'],
            ['prix_usine' => 18000, 'prix_vente' => 22000, 'prix_revendeur' => 20000],
        );

        $this->assertSame(22000, PrixVenteNatureResolver::resolve($produit->variantePrincipale()->first(), null));
    }

    public function test_fabricable_avec_tarif_nature_non_renseigne_retombe_sur_prix_vente(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable'],
            ['prix_usine' => 18000, 'prix_vente' => 22000], // prix_distributeur volontairement absent
        );

        $this->assertSame(
            22000,
            PrixVenteNatureResolver::resolve($produit->variantePrincipale()->first(), $this->client(ClientType::DISTRIBUTEUR->value))
        );
    }

    public function test_fabricable_avec_tarif_nature_a_zero_nest_jamais_confondu_avec_absent(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500ml', 'type' => 'fabricable'],
            ['prix_usine' => 18000, 'prix_vente' => 22000, 'prix_revendeur' => 0],
        );

        $this->assertSame(
            0,
            PrixVenteNatureResolver::resolve($produit->variantePrincipale()->first(), $this->client(ClientType::REVENDEUR->value))
        );
    }

    public function test_non_fabricable_ignore_les_tarifs_par_nature_meme_avec_client(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sac de riz'], // type par défaut du trait = 'materiel' (non-fabricable)
            ['prix_achat' => 100000, 'prix_vente' => 120000],
        );

        $this->assertSame(
            120000,
            PrixVenteNatureResolver::resolve($produit->variantePrincipale()->first(), $this->client(ClientType::REVENDEUR->value))
        );
    }
}
