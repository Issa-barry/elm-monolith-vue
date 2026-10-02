<?php

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site::commissionsActives() — garde-fou métier COMM-013 (docs/commissions.md), indépendant du
 * statut opérationnel du site. Défaut `true` : un site jamais configuré explicitement continue de
 * générer sa commission exactement comme avant l'introduction de ce réglage (migration additive,
 * colonne non nullable avec défaut DB).
 */
class SiteCommissionsActivesTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        return Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test-'.uniqid(), 'is_active' => true]);
    }

    public function test_defaut_a_true_pour_un_site_jamais_configure(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'Matoto',
            'type' => SiteType::USINE->value,
        ]);

        $this->assertTrue($site->fresh()->commissionsActives());
    }

    public function test_desactivation_explicite_est_respectee(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'Matoto',
            'type' => SiteType::USINE->value,
            'commissions_active' => false,
        ]);

        $this->assertFalse($site->fresh()->commissionsActives());
    }

    public function test_independant_du_statut_operationnel_du_site(): void
    {
        $site = Site::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'Matoto',
            'type' => SiteType::USINE->value,
            'statut' => 'inactive',
            'commissions_active' => true,
        ]);

        // Un site opérationnellement inactif mais avec commissions_active=true reste éligible —
        // ces deux notions sont volontairement indépendantes (décision produit 13/09/2026).
        $this->assertTrue($site->fresh()->commissionsActives());
    }
}
