<?php

namespace Tests\Unit;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\PrixOrigine;
use App\Models\Categorie;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\Organization;
use App\Services\GrossisteTarifResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Couvre GrossisteTarifResolver::resolve() — tarification Grossiste par catégorie × mode de
 * remise ET PAR CLIENT (décision produit du 05/09/2026 : chaque Grossiste négocie son propre
 * tarif, jamais une grille partagée par toute l'organisation), indépendante de
 * PrixVenteNatureResolver (colonnes par variante) : le tarif n'existe qu'au niveau
 * catégorie+client, jamais sur produit_variantes. Cf. docs/grossiste.md.
 *
 * Révision du 05/09/2026 (deuxième décision produit le même jour) : le tarif spécial est une
 * SURCHARGE facultative du prix normal, jamais une obligation — l'absence de tarif (ou de
 * catégorie) retombe sur `ProduitVariante::prix_vente`, plus aucun blocage dans ces cas.
 */
class GrossisteTarifResolverTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Categorie $categorie;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ]);
        $this->client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
    }

    private function tarif(ModeRemiseGrossiste $mode, int $prix, ?Client $client = null): CategorieTarifGrossiste
    {
        return CategorieTarifGrossiste::create([
            'organization_id' => $this->org->id,
            'client_id' => ($client ?? $this->client)->id,
            'categorie_id' => $this->categorie->id,
            'mode' => $mode->value,
            'prix' => $prix,
        ]);
    }

    public function test_resout_le_tarif_special_configure_pour_la_categorie_et_le_mode(): void
    {
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18500);
        $this->tarif(ModeRemiseGrossiste::LIVRAISON, 19000);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(18500, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
        $this->assertSame(19000, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::LIVRAISON, $this->client));
        $this->assertSame(PrixOrigine::GROSSISTE, GrossisteTarifResolver::resolveOrigine($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
    }

    public function test_deux_clients_grossistes_peuvent_avoir_des_tarifs_differents(): void
    {
        $autreClient = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18500, $this->client);
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18700, $autreClient);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(18500, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
        $this->assertSame(18700, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $autreClient));
    }

    public function test_produit_sans_categorie_retombe_sur_le_prix_normal(): void
    {
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18500);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack sans catégorie', 'type' => 'fabricable', 'categorie_id' => null],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(22000, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
        $this->assertSame(PrixOrigine::VENTE, GrossisteTarifResolver::resolveOrigine($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
    }

    public function test_aucun_tarif_special_pour_ce_client_sur_ce_mode_retombe_sur_le_prix_normal(): void
    {
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18500);
        // Aucun tarif LIVRAISON configuré volontairement.

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(22000, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::LIVRAISON, $this->client));
        $this->assertSame(PrixOrigine::VENTE, GrossisteTarifResolver::resolveOrigine($variante, ModeRemiseGrossiste::LIVRAISON, $this->client));
    }

    public function test_ne_recupere_jamais_le_tarif_dun_autre_client_retombe_sur_le_prix_normal(): void
    {
        $autreClient = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 18500, $autreClient);
        // Rien configuré pour $this->client (K2 dans l'exemple métier).

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        // Jamais 18500 (le tarif de l'autre client) : uniquement le prix normal.
        $this->assertSame(22000, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
    }

    public function test_bloque_si_le_tarif_special_ne_couvre_pas_le_prix_usine_de_reference(): void
    {
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 15000); // <= prix_usine ci-dessous

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 22000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->expectException(ValidationException::class);
        GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client);
    }

    public function test_type_sans_champ_de_reference_ignore_le_controle_de_marge(): void
    {
        $this->tarif(ModeRemiseGrossiste::ENLEVEMENT, 100); // très bas, aucune référence à violer

        // Type par défaut du trait = 'materiel', champ_prix_reference = null (aucune règle de
        // marge produit, cf. ProduitTypeDefaultSeeder).
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Bidon vide', 'categorie_id' => $this->categorie->id],
            ['prix_achat' => 5000],
        );
        $variante = $produit->variantePrincipale()->first();

        $this->assertSame(100, GrossisteTarifResolver::resolve($variante, ModeRemiseGrossiste::ENLEVEMENT, $this->client));
    }
}
