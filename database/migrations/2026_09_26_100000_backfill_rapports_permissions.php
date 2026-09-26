<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée les permissions du rapport d'activité (décision
 * du 26/09/2026, cf. docs/rapports.md) :
 *
 * - `rapports.read_own` (« Ma situation ») est accordée à TOUS les rôles existants, système comme
 *   personnalisés : chacun n'y voit que ses propres données (agent imposé côté serveur).
 * - `rapports.read` (rapport de ses agences, tous agents) n'est PAS rattrapée sur les rôles
 *   personnalisés : seuls les rôles types super_admin, admin_entreprise, manager et comptable la
 *   reçoivent ; toute autre attribution se fait explicitement dans /backoffice/roles.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER, jamais retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'rapports.read_own', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'rapports.read', 'guard_name' => 'web']);

        Role::query()->with('permissions')->get()->each(function (Role $role) {
            if (! $role->permissions->contains('name', 'rapports.read_own')) {
                $role->givePermissionTo('rapports.read_own');
            }
        });

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', ['super_admin', 'admin_entreprise', 'manager', 'comptable'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'rapports.read')) {
                    $role->givePermissionTo('rapports.read');
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Pas de rollback de données : retirer ces permissions romprait des rôles qui les auraient
     * depuis configurées volontairement.
     */
    public function down(): void
    {
        //
    }
};
