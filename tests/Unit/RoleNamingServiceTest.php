<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\RoleNamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleNamingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleNamingService $service;

    private string $orgA;

    private string $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoleNamingService;
        $this->orgA = Organization::factory()->create()->id;
        $this->orgB = Organization::factory()->create()->id;
    }

    // ── nom technique ─────────────────────────────────────────────────────────

    public function test_technical_name_generates_snake_case_from_a_label(): void
    {
        $this->assertSame('president_directeur_general', $this->service->technicalName('Président directeur Général'));
    }

    public function test_technical_name_strips_french_elision_instead_of_keeping_the_dropped_letter(): void
    {
        $this->assertSame('chef_agence', $this->service->technicalName("Chef d'agence"));
        $this->assertSame('chef_agence', $this->service->technicalName('CHEF D’AGENCE'));
    }

    public function test_technical_name_taken_is_scoped_by_organization(): void
    {
        Role::query()->create(['name' => 'chef_agence', 'guard_name' => 'web', 'organization_id' => $this->orgA]);

        $this->assertTrue($this->service->technicalNameTaken('chef_agence', $this->orgA));
        $this->assertFalse($this->service->technicalNameTaken('chef_agence', $this->orgB));
    }

    public function test_technical_name_taken_ignores_the_given_role_id(): void
    {
        $role = Role::query()->create(['name' => 'chef_agence', 'guard_name' => 'web', 'organization_id' => $this->orgA]);

        $this->assertFalse($this->service->technicalNameTaken('chef_agence', $this->orgA, $role->id));
    }

    // ── trinôme : saisie manuelle ─────────────────────────────────────────────

    public function test_normalize_trinome_uppercases_a_manual_entry(): void
    {
        $this->assertSame('PDG', $this->service->normalizeTrinome('pdg'));
        $this->assertSame('PDG', $this->service->normalizeTrinome(' Pdg '));
    }

    public function test_trinome_taken_is_case_insensitive_via_normalization(): void
    {
        Role::query()->create(['name' => 'r', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'PDG']);

        $this->assertTrue($this->service->trinomeTaken('pdg', $this->orgA));
        $this->assertTrue($this->service->trinomeTaken('PDG', $this->orgA));
        $this->assertFalse($this->service->trinomeTaken('pdg', $this->orgB));
    }

    // ── trinôme : génération automatique ─────────────────────────────────────

    public function test_unique_trinome_takes_initials_for_a_multi_word_label(): void
    {
        $this->assertSame('PDG', $this->service->uniqueTrinome('Président directeur Général', $this->orgA));
        $this->assertSame('DG', $this->service->uniqueTrinome('Directeur Général', $this->orgA));
        $this->assertSame('CA', $this->service->uniqueTrinome("Chef d'agence", $this->orgA));
    }

    public function test_unique_trinome_takes_three_letters_for_a_single_word_label(): void
    {
        $this->assertSame('CON', $this->service->uniqueTrinome('Contrôleur', $this->orgA));
    }

    public function test_unique_trinome_extends_from_the_last_word_on_collision(): void
    {
        Role::query()->create(['name' => 'r', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'RC']);

        $this->assertSame('RCO', $this->service->uniqueTrinome('Responsable Commercial', $this->orgA));
    }

    public function test_unique_trinome_falls_back_to_a_numeric_suffix_when_every_extension_collides(): void
    {
        Role::query()->create(['name' => 'r1', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CA']);
        Role::query()->create(['name' => 'r2', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CAG']);
        Role::query()->create(['name' => 'r3', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CAGE']);
        Role::query()->create(['name' => 'r4', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CAGEN']);
        Role::query()->create(['name' => 'r5', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CAGENC']);
        Role::query()->create(['name' => 'r6', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'CAGENCE']);

        $this->assertSame('CA2', $this->service->uniqueTrinome('Chef agence', $this->orgA));
    }

    public function test_unique_trinome_is_scoped_by_organization(): void
    {
        Role::query()->create(['name' => 'r', 'guard_name' => 'web', 'organization_id' => $this->orgA, 'code' => 'PDG']);

        $this->assertSame('PDG', $this->service->uniqueTrinome('Président directeur Général', $this->orgB));
    }
}
