<?php

namespace Tests\Feature\Rapports;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migration 2026_09_26_100000_backfill_rapports_permissions (décision du 26/09/2026) :
 * `rapports.read_own` pour tous les rôles existants, `rapports.read` pour les seuls rôles types.
 */
class BackfillRapportsPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_own_pour_tous_les_roles_et_read_pour_les_seuls_roles_types(): void
    {
        $org = Organization::factory()->create();
        foreach (['super_admin', 'admin_entreprise', 'manager', 'comptable', 'commerciale', 'livreur'] as $nom) {
            Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web', 'organization_id' => null]);
        }
        $personnalise = Role::create(['name' => 'caissier', 'guard_name' => 'web', 'organization_id' => $org->id]);
        $responsable = Role::create(['name' => 'responsable_agence', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $migration = require database_path('migrations/2026_09_26_100000_backfill_rapports_permissions.php');
        $migration->up();
        $migration->up();

        foreach (Role::all() as $role) {
            $this->assertTrue($role->hasPermissionTo('rapports.read_own'), "{$role->name} doit avoir rapports.read_own");
        }

        $avecRead = Role::all()->filter(fn (Role $r) => $r->hasPermissionTo('rapports.read'))
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['admin_entreprise', 'comptable', 'manager', 'super_admin'], $avecRead);
        $this->assertFalse($personnalise->fresh()->hasPermissionTo('rapports.read'));
        $this->assertFalse($responsable->fresh()->hasPermissionTo('rapports.read'));
    }
}
