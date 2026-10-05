<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — `ventes.changer_mode_remise` (ADR 0019, décision D16) : passer une précommande
 * du retrait à la livraison ou l'inverse avant le chargement. Accordée aux rôles système
 * `admin_entreprise` et `manager`, comme les autres actions du cycle des précommandes (D12). Les rôles
 * personnalisés ne la reçoivent pas : leur organisation la coche si elle le souhaite (ADR 0011).
 *
 * Non destructive et idempotente : la permission est seulement ajoutée, jamais retirée.
 */
return new class extends Migration
{
    private const PERMISSION = 'ventes.changer_mode_remise';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', ['admin_entreprise', 'manager'])
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
