<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — crée `ventes.precommander` (création d'une précommande, acompte initial
 * compris, ADR 0019) et l'accorde aux rôles système désignés par la décision D12 :
 * `admin_entreprise`, `manager` et `commerciale`. Les rôles personnalisés ne la reçoivent pas : leur
 * organisation la coche si elle le souhaite dans Rôles & Permissions (ADR 0011).
 *
 * Non destructive et idempotente : la permission est seulement ajoutée, jamais retirée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.precommander', 'guard_name' => 'web']);

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', ['admin_entreprise', 'manager', 'commerciale'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'ventes.precommander')) {
                    $role->givePermissionTo('ventes.precommander');
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : la permission a pu être configurée volontairement depuis. */
    public function down(): void
    {
        //
    }
};
