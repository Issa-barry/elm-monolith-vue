<?php

namespace Tests\Unit;

use App\Exceptions\Tresorerie\SiteCentralTresorerieIndisponibleException;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCentralTresorerieResolverTest extends TestCase
{
    use RefreshDatabase;

    private SiteCentralTresorerieResolver $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SiteCentralTresorerieResolver::class);
        $this->org = Organization::factory()->create();
    }

    public function test_le_site_central_peut_etre_une_agence(): void
    {
        $agence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'agence', 'is_central_tresorerie' => true]);

        $this->assertSame($agence->id, $this->service->central($this->org->id)->id);
    }

    public function test_aucun_site_n_est_central_par_simple_creation(): void
    {
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'agence']);

        $this->assertNull($this->service->centralOuNull($this->org->id));
    }

    public function test_designer_retire_le_role_a_l_ancien_site_central(): void
    {
        $premier = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'agence', 'is_central_tresorerie' => true]);
        $depot = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);

        $this->service->designer($depot);

        $this->assertFalse($premier->fresh()->is_central_tresorerie);
        $this->assertTrue($depot->fresh()->is_central_tresorerie);
        $this->assertSame($depot->id, $this->service->central($this->org->id)->id);
    }

    public function test_creer_un_second_site_central_retire_le_role_au_premier(): void
    {
        $premier = Site::create(['organization_id' => $this->org->id, 'nom' => 'A', 'type' => 'agence', 'is_central_tresorerie' => true]);
        $second = Site::create(['organization_id' => $this->org->id, 'nom' => 'B', 'type' => 'usine', 'is_central_tresorerie' => true]);

        $this->assertFalse($premier->fresh()->is_central_tresorerie);
        $this->assertTrue($second->fresh()->is_central_tresorerie);
        $this->assertSame(1, Site::where('organization_id', $this->org->id)->where('is_central_tresorerie', true)->count());
    }

    public function test_aucun_site_central_leve_une_exception_explicite(): void
    {
        $this->expectException(SiteCentralTresorerieIndisponibleException::class);
        $this->service->central($this->org->id);
    }

    public function test_isole_les_organisations(): void
    {
        $autreOrg = Organization::factory()->create();
        $central = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'agence', 'is_central_tresorerie' => true]);
        $centralAutreOrg = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'agence', 'is_central_tresorerie' => true]);

        $this->assertTrue($central->fresh()->is_central_tresorerie);
        $this->assertTrue($centralAutreOrg->fresh()->is_central_tresorerie);
        $this->assertSame($central->id, $this->service->central($this->org->id)->id);

        $this->expectException(SiteCentralTresorerieIndisponibleException::class);
        $this->service->central(Organization::factory()->create()->id);
    }
}
