<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permission « supprimer un support resté en brouillon » (ADR 0028).
 * Accordée une fois aux rôles système qui valident déjà les supports (`admin_entreprise`,
 * `comptable`) : le seeder ne pose plus la matrice des rôles existants (ADR 0011). Les rôles
 * personnalisés ne la reçoivent pas ; elle se coche dans /backoffice/roles → Finance.
 * Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSION = 'tresorerie.supprimer_supports';

    private const ROLES = ['admin_entreprise', 'comptable'];

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', self::ROLES)
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', self::PERMISSION)) {
                    $role->givePermissionTo(self::PERMISSION);
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
