<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\ReferenceNumeroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceNumeroServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReferenceNumeroService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReferenceNumeroService;
    }

    private function makeOrg(): Organization
    {
        return Organization::factory()->create();
    }

    public function test_genere_reference_au_format_prefixe_jjmmaa_numero(): void
    {
        $org = $this->makeOrg();

        [$reference] = $this->service->generer($org->id, 'VTE');

        $this->assertMatchesRegularExpression('/^VTE-\d{6}-\d{3}$/', $reference);
    }

    public function test_reference_contient_la_date_du_jour(): void
    {
        $org = $this->makeOrg();
        $dateAttendue = now()->format('dmy');

        [$reference] = $this->service->generer($org->id, 'DST');

        $this->assertStringContainsString("DST-{$dateAttendue}-", $reference);
    }

    public function test_premier_numero_du_jour_est_001(): void
    {
        $org = $this->makeOrg();

        [$reference, $numero] = $this->service->generer($org->id, 'TRF');

        $this->assertEquals(1, $numero);
        $this->assertStringEndsWith('-001', $reference);
    }

    public function test_increment_sequentiel_dans_la_journee(): void
    {
        $org = $this->makeOrg();

        [, $n1] = $this->service->generer($org->id, 'VTE');
        [, $n2] = $this->service->generer($org->id, 'VTE');
        [, $n3] = $this->service->generer($org->id, 'VTE');

        $this->assertEquals(1, $n1);
        $this->assertEquals(2, $n2);
        $this->assertEquals(3, $n3);
    }

    public function test_numero_padde_sur_3_chiffres(): void
    {
        $org = $this->makeOrg();

        for ($i = 0; $i < 9; $i++) {
            $this->service->generer($org->id, 'VTE');
        }
        [$reference, $numero] = $this->service->generer($org->id, 'VTE');

        $this->assertEquals(10, $numero);
        $this->assertStringEndsWith('-010', $reference);
    }

    /**
     * Décision produit du 31/08/2026 : contrairement à l'ancien compteur commande_sequences
     * (mensuel, partagé entre toutes les organisations), le nouveau compteur ne doit jamais
     * mélanger deux organisations — chacune reçoit sa propre séquence à partir de 001.
     */
    public function test_sequences_independantes_par_organisation(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        [$refA1] = $this->service->generer($orgA->id, 'VTE');
        [$refB1] = $this->service->generer($orgB->id, 'VTE');
        [$refA2] = $this->service->generer($orgA->id, 'VTE');

        $this->assertStringEndsWith('-001', $refA1);
        $this->assertStringEndsWith('-001', $refB1, 'Organisation B ne doit pas hériter du compteur de A.');
        $this->assertStringEndsWith('-002', $refA2);
    }

    /**
     * Vente/Distribution/Transfert progressent chacun sur leur propre séquence, même pour la
     * même organisation le même jour — jamais un compteur partagé entre préfixes.
     */
    public function test_sequences_independantes_par_prefixe(): void
    {
        $org = $this->makeOrg();

        [$refVte1] = $this->service->generer($org->id, 'VTE');
        [$refDst1] = $this->service->generer($org->id, 'DST');
        [$refTrf1] = $this->service->generer($org->id, 'TRF');
        [$refVte2] = $this->service->generer($org->id, 'VTE');

        $this->assertStringEndsWith('-001', $refVte1);
        $this->assertStringEndsWith('-001', $refDst1);
        $this->assertStringEndsWith('-001', $refTrf1);
        $this->assertStringEndsWith('-002', $refVte2);
        $this->assertStringStartsWith('VTE-', $refVte1);
        $this->assertStringStartsWith('DST-', $refDst1);
        $this->assertStringStartsWith('TRF-', $refTrf1);
    }

    /**
     * Le compteur est journalier (cohérent avec le format affiché JJMMAA) — décision produit du
     * 31/08/2026, qui remplace l'ancien compteur mensuel de CommandeNumeroService : une nouvelle
     * journée repart à 001, jamais une continuation du jour précédent.
     */
    public function test_reset_journalier_du_compteur(): void
    {
        $org = $this->makeOrg();

        DB::table('reference_sequences')->insert([
            'organization_id' => $org->id,
            'prefixe' => 'VTE',
            'periode' => now()->subDay()->format('dmy'),
            'compteur' => 999,
        ]);

        [$reference, $numero] = $this->service->generer($org->id, 'VTE');

        $this->assertEquals(1, $numero);
        $this->assertStringEndsWith('-001', $reference);
    }

    public function test_references_sont_uniques(): void
    {
        $org = $this->makeOrg();

        [$ref1] = $this->service->generer($org->id, 'VTE');
        [$ref2] = $this->service->generer($org->id, 'VTE');
        [$ref3] = $this->service->generer($org->id, 'VTE');

        $this->assertNotEquals($ref1, $ref2);
        $this->assertNotEquals($ref2, $ref3);
    }

    public function test_overflow_lance_exception_apres_999(): void
    {
        $org = $this->makeOrg();

        DB::table('reference_sequences')->insert([
            'organization_id' => $org->id,
            'prefixe' => 'VTE',
            'periode' => now()->format('dmy'),
            'compteur' => 999,
        ]);

        $this->expectException(\OverflowException::class);

        $this->service->generer($org->id, 'VTE');
    }
}
