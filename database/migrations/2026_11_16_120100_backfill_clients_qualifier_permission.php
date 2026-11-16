<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permission « Clients — qualifier un client créé à l'inscription »
 * (ADR 0066). Valeur de départ accordée une fois aux administrateurs (super_admin,
 * admin_entreprise) ; les autres rôles la reçoivent depuis /backoffice/roles → Clients & Contacts.
 * Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSION = 'clients.qualifier';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->whereIn('name', ['super_admin', 'admin_entreprise'])
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
