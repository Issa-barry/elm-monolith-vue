<?php

namespace Tests\Unit;

use App\Services\CommandeNumeroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommandeNumeroServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommandeNumeroService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommandeNumeroService;
    }

    public function test_genere_reference_au_format_cmd(): void
    {
        [$reference] = $this->service->generer();

        $this->assertMatchesRegularExpression('/^CMD-\d{6}-\d{3}$/', $reference);
    }

    public function test_reference_contient_date_du_jour(): void
    {
        $dateAttendue = now()->format('dmy');

        [$reference] = $this->service->generer();

        $this->assertStringContainsString("CMD-{$dateAttendue}-", $reference);
    }

    public function test_premier_numero_du_mois_est_001(): void
    {
        [$reference, $numero] = $this->service->generer();

        $this->assertEquals(1, $numero);
        $this->assertStringEndsWith('-001', $reference);
    }

    public function test_increment_sequentiel_dans_le_mois(): void
    {
        [, $n1] = $this->service->generer();
        [, $n2] = $this->service->generer();
        [, $n3] = $this->service->generer();

        $this->assertEquals(1, $n1);
        $this->assertEquals(2, $n2);
        $this->assertEquals(3, $n3);
    }

    public function test_references_sont_uniques(): void
    {
        [$ref1] = $this->service->generer();
        [$ref2] = $this->service->generer();
        [$ref3] = $this->service->generer();

        $this->assertNotEquals($ref1, $ref2);
        $this->assertNotEquals($ref2, $ref3);
    }

    public function test_numero_padde_sur_3_chiffres(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->service->generer();
        }
        [$reference, $numero] = $this->service->generer();

        $this->assertEquals(10, $numero);
        $this->assertStringEndsWith('-010', $reference);
    }

    public function test_overflow_lance_exception_apres_999(): void
    {
        DB::table('commande_sequences')->insert([
            'periode' => now()->format('Y-m'),
            'compteur' => 999,
        ]);

        $this->expectException(\OverflowException::class);

        $this->service->generer();
    }
}
