<?php

namespace Tests\Feature;

use App\Models\Organization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migration 2026_09_28_100000_backfill_matrices_roles_systeme : le seeder ne resynchronisant plus
 * les rôles système existants, les permissions ajoutées à leurs matrices par défaut depuis la
 * dernière mise en production leur sont rattrapées — sans rien retirer, ni toucher aux rôles
 * personnalisés.
 */
class BackfillMatricesRolesSystemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_roles_systeme_recoivent_les_ajouts_sans_rien_perdre(): void
    {
        $org = Organization::factory()->create();
        Permission::firstOrCreate(['name' => 'ventes.valider_chargement', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);

        $commerciale = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web', 'organization_id' => null]);
        $commerciale->givePermissionTo(['ventes.read', 'ventes.valider_chargement']);
        $comptable = Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web', 'organization_id' => null]);
        $personnalise = Role::create(['name' => 'caissier_agence', 'guard_name' => 'web', 'organization_id' => $org->id]);

        $migration = require database_path('migrations/2026_09_28_100000_backfill_matrices_roles_systeme.php');
        $migration->up();
        $migration->up();

        $commerciale = $commerciale->fresh();
        $this->assertTrue($commerciale->hasPermissionTo('rapports.read_own'));
        $this->assertTrue($commerciale->hasPermissionTo('ventes.valider_chargement'), 'permission configurée conservée');
        $this->assertTrue($commerciale->hasPermissionTo('ventes.read'));
        $this->assertFalse($commerciale->hasPermissionTo('rapports.read'));

        $comptable = $comptable->fresh();
        foreach (['ventes.exporter', 'tresorerie.verser', 'tresorerie.valider_supports', 'rapports.read_own', 'rapports.read'] as $nom) {
            $this->assertTrue($comptable->hasPermissionTo($nom), "{$nom} rattrapée pour comptable");
        }

        $this->assertSame(0, $personnalise->fresh()->permissions()->count());
    }

    /**
     * Les ajouts rattrapés doivent tous figurer dans la matrice par défaut actuelle : une instance
     * existante (rattrapée par la migration) et une instance neuve (matrice posée par le seeder) ne
     * doivent pas diverger sur ces permissions.
     */
    public function test_les_ajouts_sont_coherents_avec_la_matrice_par_defaut_du_seeder(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $migration = require database_path('migrations/2026_09_28_100000_backfill_matrices_roles_systeme.php');
        $ajouts = (new \ReflectionClassConstant($migration, 'AJOUTS'))->getValue();

        foreach ($ajouts as $roleName => $permissions) {
            $role = Role::whereNull('organization_id')->where('name', $roleName)->sole();
            foreach ($permissions as $nom) {
                $this->assertTrue($role->hasPermissionTo($nom), "{$nom} absente de la matrice par défaut de {$roleName}");
            }
        }
    }
}
