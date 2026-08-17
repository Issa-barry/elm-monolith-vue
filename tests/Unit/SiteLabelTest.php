<?php

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site::getLabelAttribute() — source unique du libellé "{Type} de {Nom}" affiché partout
 * (UserInfo.vue, HeaderWidget.vue via HandleInertiaRequests::defaultSite()). Doit produire le
 * même résultat qu'un nom auto-descriptif ait déjà été généré par SiteNamingService (onboarding)
 * ou qu'il s'agisse d'un simple libellé court saisi manuellement (CRUD Sites classique).
 */
class SiteLabelTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        return Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test-'.uniqid(), 'is_active' => true]);
    }

    public function test_nom_court_est_prefixe_du_type(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'Matoto',
            'type' => SiteType::USINE->value,
        ]);

        $this->assertSame('Usine de Matoto', $site->label);
    }

    /**
     * Cas central de cette régression : un nom déjà auto-descriptif (généré par
     * SiteNamingService::generateName() à l'onboarding) ne doit jamais être re-préfixé.
     */
    public function test_nom_deja_auto_descriptif_nest_pas_re_prefixe(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'Usine de Matoto',
            'type' => SiteType::USINE->value,
        ]);

        $this->assertSame('Usine de Matoto', $site->label);
        $this->assertNotSame('Usine de Usine de Matoto', $site->label);
    }

    public function test_detection_insensible_a_la_casse(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'usine de matoto',
            'type' => SiteType::USINE->value,
        ]);

        $this->assertSame('usine de matoto', $site->label);
    }
}
