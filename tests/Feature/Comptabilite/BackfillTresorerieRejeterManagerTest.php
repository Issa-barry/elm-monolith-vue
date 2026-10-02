<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migration 2026_09_27_100000_backfill_tresorerie_rejeter_manager (décision du 27/09/2026) : le rôle
 * système Manager reçoit « Contester » (`tresorerie.rejeter`) sans perdre aucune permission ; les
 * autres rôles ne sont pas touchés.
 */
class BackfillTresorerieRejeterManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_manager_systeme_recoit_contester_sans_rien_perdre(): void
    {
        $org = Organization::factory()->create();
        foreach (['tresorerie.read', 'tresorerie.verser', 'tresorerie.recevoir'] as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web', 'organization_id' => null]);
        $manager->givePermissionTo(['tresorerie.read', 'tresorerie.verser', 'tresorerie.recevoir']);
        $commerciale = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web', 'organization_id' => null]);
        $personnalise = Role::create(['name' => 'caissier_agence', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $migration = require database_path('migrations/2026_09_27_100000_backfill_tresorerie_rejeter_manager.php');
        $migration->up();
        $migration->up();

        $manager = $manager->fresh();
        $this->assertTrue($manager->hasPermissionTo('tresorerie.rejeter'));
        foreach (['tresorerie.read', 'tresorerie.verser', 'tresorerie.recevoir'] as $nom) {
            $this->assertTrue($manager->hasPermissionTo($nom), "{$nom} conservée");
        }
        $this->assertFalse($commerciale->fresh()->hasPermissionTo('tresorerie.rejeter'));
        $this->assertFalse($personnalise->fresh()->hasPermissionTo('tresorerie.rejeter'));
    }
}
