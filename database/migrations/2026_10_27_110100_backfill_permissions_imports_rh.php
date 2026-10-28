<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions de l'import des dossiers et contrats RH, accordées par défaut
 * à super_admin et admin_entreprise (décision D4 du 27/10/2026). Délégables à un autre rôle dans
 * /backoffice/roles. Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['imports-rh.create', 'imports-rh.read'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereIn('name', ['super_admin', 'admin_entreprise'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                foreach (self::PERMISSIONS as $name) {
                    if (! $role->permissions->contains('name', $name)) {
                        $role->givePermissionTo($name);
                    }
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : ces permissions ont pu être configurées volontairement depuis. */
    public function down(): void
    {
        //
    }
};
