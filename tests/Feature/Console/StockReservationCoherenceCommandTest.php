<?php

namespace Tests\Feature\Console;

use App\Enums\StatutReservationStock;
use App\Models\Organization;
use App\Models\Site;
use App\Models\StockReservation;
use App\Models\VarianteStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * stock:verifier-coherence-reservations doit détecter (jamais corriger) tout écart entre
 * variante_stocks.qte_reservee et la somme des réservations actives — cf.
 * StockReservationCoherenceCommand, introduite le 25/08/2026 (audit stock, item Lot 0 #7-8).
 */
class StockReservationCoherenceCommandTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $site;

    private string $varianteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit Test']);
        $this->varianteId = $produit->variantePrincipale()->first()->id;
    }

    public function test_aucun_ecart_quand_compteur_et_reservations_coincident(): void
    {
        $stock = VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 40,
        ]);
        StockReservation::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'produit_variante_id' => $this->varianteId,
            'quantite' => 40,
            'statut' => StatutReservationStock::ACTIVE,
            'source_type' => 'commande_test',
            'source_id' => 'ligne-1',
            'reserved_at' => now(),
        ]);

        $this->artisan('stock:verifier-coherence-reservations')
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);

        // Aucune correction : le compteur reste strictement inchangé.
        $this->assertEquals(40, $stock->fresh()->qte_reservee);
    }

    public function test_detecte_un_ecart_sans_jamais_corriger_le_compteur(): void
    {
        // Compteur désynchronisé volontairement (simule un bug applicatif ou une intervention
        // manuelle en base) : qte_reservee=40 mais aucune réservation active en preuve.
        $stock = VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 40,
        ]);

        $this->artisan('stock:verifier-coherence-reservations')
            ->expectsOutputToContain('1 écart(s) détecté(s)')
            ->assertExitCode(1);

        // Toujours 40 après l'exécution : la commande ne corrige jamais, seulement détecte.
        $this->assertEquals(40, $stock->fresh()->qte_reservee);
    }

    public function test_detecte_une_reservation_active_sans_ligne_variante_stock_correspondante(): void
    {
        // Réservation active « orpheline » : aucune ligne variante_stocks pour cette variante ×
        // site (scénario extrême, mais le contrôle doit aussi couvrir ce sens).
        StockReservation::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'produit_variante_id' => $this->varianteId,
            'quantite' => 25,
            'statut' => StatutReservationStock::ACTIVE,
            'source_type' => 'commande_test',
            'source_id' => 'ligne-orpheline',
            'reserved_at' => now(),
        ]);

        $this->artisan('stock:verifier-coherence-reservations')
            ->expectsOutputToContain('1 écart(s) détecté(s)')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $this->varianteId,
            'site_id' => $this->site->id,
        ]);
    }

    public function test_filtre_par_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $autreProduit = $this->makeProduitAvecVariante($autreOrg, ['nom' => 'Autre Produit']);
        VarianteStock::create([
            'organization_id' => $autreOrg->id,
            'produit_variante_id' => $autreProduit->variantePrincipale()->first()->id,
            'site_id' => $autreSite->id,
            'qte_stock' => 100,
            'qte_reservee' => 40,
        ]);

        // Aucun écart pour $this->org : le filtre --organization ne doit pas remonter l'écart
        // de l'AUTRE organisation.
        $this->artisan('stock:verifier-coherence-reservations', ['--organization' => $this->org->id])
            ->expectsOutputToContain('Aucun écart détecté')
            ->assertExitCode(0);
    }
}
