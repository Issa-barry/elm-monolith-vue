<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Le montant à encaisser par l'usine varie selon que le véhicule assigné est
 * "pris en charge par l'usine" ou non :
 *  - pris en charge  : total = qte × prix_vente.
 *  - non pris en charge : total = qte × prix_usine.
 *
 * Cette notion est indépendante de l'éligibilité aux commissions
 * (Vehicule::commission_eligible) — voir CommandeVenteCommissionEligibiliteTest
 * pour les tests de génération de commission et les 4 combinaisons possibles.
 */
class CommandeVenteModeTarificationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeProduit(int $prixVente = 5000, int $prixUsine = 3500): Produit
    {
        return Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Pack Eau',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_vente' => $prixVente,
            'prix_usine' => $prixUsine,
        ]);
    }

    private function makeVehicule(bool $prisEnChargeParUsine, int $capacite = 100): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => $capacite,
            'pris_en_charge_par_usine' => $prisEnChargeParUsine,
        ]);
    }

    // ── store : total selon pris_en_charge_par_usine ─────────────────────────

    public function test_store_uses_prix_vente_when_vehicule_pris_en_charge(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehicule = $this->makeVehicule(prisEnChargeParUsine: true);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertEquals(500_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'total_ligne' => 500_000,
        ]);
    }

    public function test_store_uses_prix_usine_when_vehicule_non_pris_en_charge(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehicule = $this->makeVehicule(prisEnChargeParUsine: false);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertEquals(350_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_usine', $commande->mode_tarification_snapshot->value);

        $this->assertDatabaseHas('commande_vente_lignes', [
            'commande_vente_id' => $commande->id,
            'total_ligne' => 350_000,
        ]);
    }

    public function test_store_direct_client_sale_always_uses_prix_vente(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->latest()->first();

        $this->assertEquals(50_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_vente', $commande->mode_tarification_snapshot->value);
    }

    // ── validerChargement : recalcul sur quantité réellement chargée ─────────

    public function test_valider_chargement_recalcule_le_total_au_prix_usine_sur_quantite_chargee(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehicule = $this->makeVehicule(prisEnChargeParUsine: false, capacite: 100);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 350_000,
            'mode_tarification_snapshot' => 'prix_usine',
        ]);

        $ligne = $commande->lignes()->create([
            'produit_id' => $produit->id,
            'quantite_demandee' => 100,
            'prix_usine_snapshot' => 3500.0,
            'prix_vente_snapshot' => 5000.0,
            'total_ligne' => 350_000.0,
        ]);

        // En production, ces transitions n'ont jamais lieu hors d'une requête
        // authentifiée (created_by du mouvement de stock en dépend) — on
        // s'assure donc ici d'un utilisateur courant, comme le ferait le
        // vrai workflow HTTP.
        $this->actingAs($this->user);

        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);

        // Écart au chargement : seulement 90 packs réellement chargés (casse).
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 90,
            'type_ecart' => 'casse',
        ]]);

        $commande->refresh();

        $this->assertEquals(315_000.0, (float) $commande->total_commande);
        $this->assertEquals(315_000.0, (float) $commande->lignes()->first()->total_ligne);
    }

    // ── update (brouillon) : le mode suit le véhicule assigné au moment de l'édition ──

    public function test_update_recalcule_le_mode_quand_le_vehicule_change(): void
    {
        $produit = $this->makeProduit(prixVente: 5000, prixUsine: 3500);
        $vehiculePrisEnCharge = $this->makeVehicule(prisEnChargeParUsine: true, capacite: 100);
        $vehiculeNonPrisEnCharge = $this->makeVehicule(prisEnChargeParUsine: false, capacite: 100);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehiculePrisEnCharge->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 500_000,
            'mode_tarification_snapshot' => 'prix_vente',
        ]);
        $commande->lignes()->create([
            'produit_id' => $produit->id,
            'quantite_demandee' => 100,
            'prix_usine_snapshot' => 3500.0,
            'prix_vente_snapshot' => 5000.0,
            'total_ligne' => 500_000.0,
        ]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'vehicule_id' => $vehiculeNonPrisEnCharge->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        $commande->refresh();

        $this->assertEquals(350_000.0, (float) $commande->total_commande);
        $this->assertSame('prix_usine', $commande->mode_tarification_snapshot->value);
    }
}
