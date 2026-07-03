<?php

namespace Tests\Feature\Console;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenererPeriodesManquantesTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_les_periodes_manquantes_pour_toutes_les_organisations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->artisan('periodes:generer-manquantes', ['--annee' => 2026])
            ->assertExitCode(0);

        // 3 types × 12 mois × 2 quinzaines par organisation.
        $this->assertDatabaseCount('paiement_periodes', 144);
        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $orgA->id,
            'reference' => 'PAY-202607-P1-LIV',
        ]);
        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $orgB->id,
            'reference' => 'PAY-202612-P2-SAL',
        ]);
    }

    public function test_est_idempotent_sur_deux_executions(): void
    {
        Organization::factory()->create();

        $this->artisan('periodes:generer-manquantes', ['--annee' => 2026]);
        $this->artisan('periodes:generer-manquantes', ['--annee' => 2026]);

        $this->assertDatabaseCount('paiement_periodes', 72);
    }
}
