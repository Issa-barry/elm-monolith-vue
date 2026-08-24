<?php

namespace Tests\Unit;

use App\Enums\StatutReservationStock;
use App\Models\Organization;
use App\Models\Site;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Couvre le primitif de réservation de stock (StockReservationService) introduit le 24/08/2026 :
 * variante_stocks.qte_reservee est un compteur dérivé de StockReservation, jamais la source de
 * vérité (cf. commentaire de classe du service).
 */
class StockReservationServiceTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $user;

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
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Produit Test']);
        $this->varianteId = $produit->variantePrincipale()->first()->id;
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->varianteId, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    public function test_reserver_incremente_qte_reservee_et_trace_une_reservation_active(): void
    {
        $this->seedStock(100);

        StockReservationService::reserver(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            quantite: 40,
            sourceType: 'commande_test',
            sourceId: 'ligne-1',
            userId: $this->user->id,
        );

        $this->assertSame(40, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
        $this->assertDatabaseHas('stock_reservations', [
            'produit_variante_id' => $this->varianteId,
            'site_id' => $this->site->id,
            'source_type' => 'commande_test',
            'source_id' => 'ligne-1',
            'quantite' => 40,
            'statut' => StatutReservationStock::ACTIVE->value,
        ]);
    }

    public function test_reserver_au_dela_du_disponible_est_refusee_et_ne_laisse_aucune_trace(): void
    {
        $this->seedStock(30);

        try {
            StockReservationService::reserver(
                varianteId: $this->varianteId,
                siteId: $this->site->id,
                orgId: $this->org->id,
                quantite: 50,
                sourceType: 'commande_test',
                sourceId: 'ligne-1',
                userId: $this->user->id,
            );
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        $this->assertSame(0, StockReservation::count());
        $this->assertSame(0, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
    }

    public function test_une_deuxieme_reservation_ne_peut_pas_depasser_le_disponible_restant(): void
    {
        $this->seedStock(100);

        StockReservationService::reserver(
            varianteId: $this->varianteId,
            siteId: $this->site->id,
            orgId: $this->org->id,
            quantite: 70,
            sourceType: 'commande_test',
            sourceId: 'ligne-1',
            userId: $this->user->id,
        );

        // 100 physique - 70 déjà réservés = 30 disponibles ; une seconde source qui en demande
        // 40 doit être refusée — exactement le scénario "deux commandes concurrentes promettent
        // le même stock" que ce mécanisme corrige.
        try {
            StockReservationService::reserver(
                varianteId: $this->varianteId,
                siteId: $this->site->id,
                orgId: $this->org->id,
                quantite: 40,
                sourceType: 'commande_test',
                sourceId: 'ligne-2',
                userId: $this->user->id,
            );
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            // attendu
        }

        $this->assertSame(70, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
    }

    public function test_reserver_est_idempotent_pour_la_meme_source(): void
    {
        $this->seedStock(100);

        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);
        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);

        $this->assertSame(1, StockReservation::count());
        $this->assertSame(40, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
    }

    public function test_liberer_decremente_qte_reservee_et_marque_la_reservation_liberee(): void
    {
        $this->seedStock(100);
        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);

        StockReservationService::liberer('commande_test', 'ligne-1', $this->site->id, $this->org->id);

        $this->assertSame(0, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
        $this->assertDatabaseHas('stock_reservations', [
            'source_type' => 'commande_test',
            'source_id' => 'ligne-1',
            'statut' => StatutReservationStock::LIBEREE->value,
        ]);
    }

    public function test_liberer_est_idempotent_sans_reservation_active(): void
    {
        StockReservationService::liberer('commande_test', 'inexistante', $this->site->id, $this->org->id);

        $this->assertSame(0, StockReservation::count());
    }

    public function test_consommer_decremente_qte_reservee_et_marque_la_reservation_consommee(): void
    {
        $this->seedStock(100);
        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);

        StockReservationService::consommer('commande_test', 'ligne-1', $this->site->id, $this->org->id);

        $this->assertSame(0, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
        $this->assertDatabaseHas('stock_reservations', [
            'source_type' => 'commande_test',
            'source_id' => 'ligne-1',
            'statut' => StatutReservationStock::CONSOMMEE->value,
        ]);
    }

    public function test_quantite_reservee_active_pour_source_ignore_les_reservations_liberees(): void
    {
        $this->seedStock(100);
        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);

        $this->assertSame(40, StockReservationService::quantiteReserveeActivePourSource('commande_test', 'ligne-1', $this->site->id, $this->org->id));

        StockReservationService::liberer('commande_test', 'ligne-1', $this->site->id, $this->org->id);

        $this->assertSame(0, StockReservationService::quantiteReserveeActivePourSource('commande_test', 'ligne-1', $this->site->id, $this->org->id));
    }

    /**
     * Défense en profondeur (25/08/2026) : liberer()/consommer() filtrent désormais
     * explicitement par organization_id — une organisation ne doit jamais pouvoir agir sur une
     * réservation d'une autre organisation, même en connaissant son (source_type, source_id,
     * site_id) exacts (jamais réaliste en pratique vu l'unicité globale des ULID, mais le filtre
     * doit être une défense explicite, pas une dépendance implicite).
     */
    public function test_liberer_ignore_une_reservation_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();

        $this->seedStock(100);
        StockReservationService::reserver($this->varianteId, $this->site->id, $this->org->id, 40, 'commande_test', 'ligne-1', $this->user->id);

        // Tente de libérer avec le même (source_type, source_id, site_id) mais l'ID d'une AUTRE
        // organisation : doit être un no-op, la réservation reste active.
        StockReservationService::liberer('commande_test', 'ligne-1', $this->site->id, $autreOrg->id);

        $this->assertSame(40, VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $this->site->id)->value('qte_reservee'));
        $this->assertDatabaseHas('stock_reservations', [
            'source_type' => 'commande_test',
            'source_id' => 'ligne-1',
            'statut' => StatutReservationStock::ACTIVE->value,
        ]);
    }
}
