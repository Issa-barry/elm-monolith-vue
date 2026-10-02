<?php

namespace Tests\Unit;

use App\Exceptions\Tresorerie\SiegePrincipalIndisponibleException;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Tresorerie\SiegeResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiegeResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private SiegeResolverService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SiegeResolverService::class);
        $this->org = Organization::factory()->create();
    }

    public function test_le_premier_site_siege_devient_automatiquement_principal(): void
    {
        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Siège',
            'type' => 'siege',
            'localisation' => 'Conakry',
        ]);

        $this->assertTrue($site->fresh()->is_siege_principal);
        $this->assertSame($site->id, $this->service->principal($this->org->id)->id);
    }

    public function test_un_deuxieme_site_siege_ne_devient_pas_principal_automatiquement(): void
    {
        $premier = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège A', 'type' => 'siege', 'localisation' => 'Conakry']);
        $second = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège B', 'type' => 'siege', 'localisation' => 'Kindia']);

        $this->assertTrue($premier->fresh()->is_siege_principal);
        $this->assertFalse($second->fresh()->is_siege_principal);
        $this->assertSame($premier->id, $this->service->principal($this->org->id)->id);
    }

    public function test_assigner_principal_retire_le_flag_de_l_ancien(): void
    {
        $premier = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège A', 'type' => 'siege', 'localisation' => 'Conakry']);
        $second = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège B', 'type' => 'siege', 'localisation' => 'Kindia']);

        $this->service->assignerPrincipal($second);

        $this->assertFalse($premier->fresh()->is_siege_principal);
        $this->assertTrue($second->fresh()->is_siege_principal);
        $this->assertSame($second->id, $this->service->principal($this->org->id)->id);
    }

    public function test_aucun_siege_leve_une_exception_explicite(): void
    {
        $this->expectException(SiegePrincipalIndisponibleException::class);
        $this->service->principal($this->org->id);
    }

    public function test_assigner_principal_refuse_un_site_qui_n_est_pas_de_type_siege(): void
    {
        $depot = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot', 'localisation' => 'Conakry']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->assignerPrincipal($depot);
    }

    public function test_isole_les_organisations(): void
    {
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $autreOrg = Organization::factory()->create();

        $this->expectException(SiegePrincipalIndisponibleException::class);
        $this->service->principal($autreOrg->id);
    }
}
