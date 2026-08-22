<?php

namespace Tests\Unit;

use App\Models\FonctionRh;
use App\Models\Organization;
use App\Services\Rh\FonctionRhNamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FonctionRhNamingServiceTest extends TestCase
{
    use RefreshDatabase;

    private FonctionRhNamingService $service;

    private string $orgA;

    private string $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FonctionRhNamingService;
        $this->orgA = Organization::factory()->create()->id;
        $this->orgB = Organization::factory()->create()->id;
    }

    public function test_normalize_trinome_uppercases_a_manual_entry(): void
    {
        $this->assertSame('GDE', $this->service->normalizeTrinome('gde'));
        $this->assertSame('GDE', $this->service->normalizeTrinome(' Gde '));
    }

    public function test_code_taken_is_scoped_by_organization(): void
    {
        FonctionRh::create(['organization_id' => $this->orgA, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);

        $this->assertTrue($this->service->codeTaken('GDE', $this->orgA));
        $this->assertFalse($this->service->codeTaken('GDE', $this->orgB));
    }

    public function test_libelle_taken_is_scoped_by_organization(): void
    {
        FonctionRh::create(['organization_id' => $this->orgA, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);

        $this->assertTrue($this->service->libelleTaken('Gérant de dépôt', $this->orgA));
        $this->assertFalse($this->service->libelleTaken('Gérant de dépôt', $this->orgB));
    }

    public function test_code_taken_ignores_the_given_fonction_id(): void
    {
        $fonction = FonctionRh::create(['organization_id' => $this->orgA, 'libelle' => 'Gérant de dépôt', 'code' => 'GDE', 'is_active' => true]);

        $this->assertFalse($this->service->codeTaken('GDE', $this->orgA, $fonction->id));
    }

    public function test_unique_trinome_takes_initials_for_a_multi_word_label(): void
    {
        $this->assertSame('GDD', $this->service->uniqueTrinome('Gérant de dépôt', $this->orgA));
        $this->assertSame('CA', $this->service->uniqueTrinome("Chef d'agence", $this->orgA));
    }

    public function test_unique_trinome_takes_three_letters_for_a_single_word_label(): void
    {
        $this->assertSame('COM', $this->service->uniqueTrinome('Comptable', $this->orgA));
    }

    public function test_unique_trinome_extends_from_the_last_word_on_collision(): void
    {
        FonctionRh::create(['organization_id' => $this->orgA, 'libelle' => 'Responsable Commercial existant', 'code' => 'RC', 'is_active' => true]);

        $this->assertSame('RCO', $this->service->uniqueTrinome('Responsable Commercial', $this->orgA));
    }

    public function test_unique_trinome_is_scoped_by_organization(): void
    {
        FonctionRh::create(['organization_id' => $this->orgA, 'libelle' => 'Gérant de dépôt', 'code' => 'GDD', 'is_active' => true]);

        // Même libellé, autre organisation : le trinôme "GDD" est de nouveau disponible —
        // aucune fonction n'est jamais partagée/globale (décision finale du 2026-08-21).
        $this->assertSame('GDD', $this->service->uniqueTrinome('Gérant de dépôt', $this->orgB));
    }
}
