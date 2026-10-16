<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migration 2026_10_15_100000_backfill_tresorerie_supprimer_supports_permission (ADR 0028) : les
 * rôles système qui valident les supports (`admin_entreprise`, `comptable`) reçoivent la suppression
 * des brouillons sans rien perdre ; les autres rôles, système ou personnalisés, ne sont pas touchés.
 */
class BackfillTresorerieSupprimerSupportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_entreprise_et_comptable_recoivent_la_permission_les_autres_non(): void
    {
        $org = Organization::factory()->create();
        Permission::firstOrCreate(['name' => 'tresorerie.valider_supports', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web', 'organization_id' => null]);
        $admin->givePermissionTo('tresorerie.valider_supports');
        $comptable = Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web', 'organization_id' => null]);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web', 'organization_id' => null]);
        $personnalise = Role::create(['name' => 'caissier_agence', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $migration = require database_path('migrations/2026_10_15_100000_backfill_tresorerie_supprimer_supports_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($admin->fresh()->hasPermissionTo('tresorerie.supprimer_supports'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('tresorerie.valider_supports'), 'aucune permission perdue');
        $this->assertTrue($comptable->fresh()->hasPermissionTo('tresorerie.supprimer_supports'));
        $this->assertFalse($manager->fresh()->hasPermissionTo('tresorerie.supprimer_supports'));
        $this->assertFalse($personnalise->fresh()->hasPermissionTo('tresorerie.supprimer_supports'));
    }
}
