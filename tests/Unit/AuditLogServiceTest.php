<?php

namespace Tests\Unit;

use App\Services\AuditLogService;
use PHPUnit\Framework\TestCase;

class AuditLogServiceTest extends TestCase
{
    private AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditLogService;
    }

    // ── diff: no change ───────────────────────────────────────────────────────

    public function test_diff_returns_null_null_when_nothing_changed(): void
    {
        $snap = [
            'vehicule_id' => 1,
            'vehicule_nom' => 'Camion A',
            'client_id' => null,
            'client_nom' => null,
            'total_commande' => 5000.00,
            'statut' => 'brouillon',
            'lignes' => [
                ['produit_id' => 10, 'produit_nom' => 'P1', 'qte' => 2, 'prix_vente_snapshot' => 100.0],
            ],
        ];

        [$old, $new] = $this->service->diff($snap, $snap);

        $this->assertNull($old);
        $this->assertNull($new);
    }

    // ── diff: scalar change ───────────────────────────────────────────────────

    public function test_diff_detects_vehicule_change(): void
    {
        $before = [
            'vehicule_id' => 1,
            'vehicule_nom' => 'Camion A',
            'client_id' => null,
            'client_nom' => null,
            'total_commande' => 5000.0,
            'statut' => 'brouillon',
            'lignes' => [],
        ];

        $after = array_merge($before, ['vehicule_id' => 2, 'vehicule_nom' => 'Camion B']);

        [$old, $new] = $this->service->diff($before, $after);

        $this->assertNotNull($old);
        $this->assertNotNull($new);
        $this->assertSame(1, $old['vehicule_id']);
        $this->assertSame(2, $new['vehicule_id']);
        $this->assertSame('Camion A', $old['vehicule_nom']);
        $this->assertSame('Camion B', $new['vehicule_nom']);
        $this->assertArrayNotHasKey('total_commande', $old);
    }

    // ── diff: numeric normalisation ───────────────────────────────────────────

    public function test_diff_ignores_float_string_mismatch_for_same_value(): void
    {
        $before = ['vehicule_id' => 1, 'vehicule_nom' => 'A', 'client_id' => null, 'client_nom' => null, 'total_commande' => '5000', 'statut' => 'brouillon', 'lignes' => []];
        $after = array_merge($before, ['total_commande' => 5000.0]);

        [$old, $new] = $this->service->diff($before, $after);

        $this->assertNull($old);
        $this->assertNull($new);
    }

    // ── diff: lignes change ───────────────────────────────────────────────────

    public function test_diff_detects_lignes_change(): void
    {
        $base = ['vehicule_id' => 1, 'vehicule_nom' => 'A', 'client_id' => null, 'client_nom' => null, 'total_commande' => 1000.0, 'statut' => 'brouillon'];

        $before = array_merge($base, [
            'lignes' => [
                ['produit_id' => 10, 'produit_nom' => 'P1', 'qte' => 1, 'prix_vente_snapshot' => 1000.0],
            ],
        ]);

        $after = array_merge($base, [
            'lignes' => [
                ['produit_id' => 10, 'produit_nom' => 'P1', 'qte' => 2, 'prix_vente_snapshot' => 1000.0],
            ],
        ]);

        [$old, $new] = $this->service->diff($before, $after);

        $this->assertNotNull($old);
        $this->assertNotNull($new);
        $this->assertArrayHasKey('lignes', $old);
        $this->assertArrayHasKey('lignes', $new);
        $this->assertSame(1, $old['lignes'][0]['qte']);
        $this->assertSame(2, $new['lignes'][0]['qte']);
    }

    // ── diff: lignes sort order is normalised ─────────────────────────────────

    public function test_diff_ignores_lignes_sort_order(): void
    {
        $base = ['vehicule_id' => 1, 'vehicule_nom' => 'A', 'client_id' => null, 'client_nom' => null, 'total_commande' => 200.0, 'statut' => 'brouillon'];

        $ligne1 = ['produit_id' => 10, 'produit_nom' => 'P1', 'qte' => 1, 'prix_vente_snapshot' => 100.0];
        $ligne2 = ['produit_id' => 20, 'produit_nom' => 'P2', 'qte' => 1, 'prix_vente_snapshot' => 100.0];

        $before = array_merge($base, ['lignes' => [$ligne1, $ligne2]]);
        $after = array_merge($base, ['lignes' => [$ligne2, $ligne1]]);

        [$old, $new] = $this->service->diff($before, $after);

        $this->assertNull($old);
        $this->assertNull($new);
    }
}
