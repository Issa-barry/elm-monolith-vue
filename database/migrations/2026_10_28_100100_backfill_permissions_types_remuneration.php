<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions du référentiel des primes et avantages (ADR 0038). La
 * validation (`rh-types-remuneration.valider`) n'est accordée qu'au super administrateur ;
 * admin_entreprise reçoit la gestion (créer, lire, modifier, supprimer). Délégables dans
 * /backoffice/roles. Non destructive et idempotente.
 */
return new class extends Migration
{
    private const GESTION = [
        'rh-types-remuneration.create',
        'rh-types-remuneration.read',
        'rh-types-remuneration.update',
        'rh-types-remuneration.delete',
    ];

    private const VALIDATION = 'rh-types-remuneration.valider';

    public function up(): void
    {
        foreach ([...self::GESTION, self::VALIDATION] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $parRole = [
            'super_admin' => [...self::GESTION, self::VALIDATION],
            'admin_entreprise' => self::GESTION,
        ];

        Role::query()
            ->whereIn('name', array_keys($parRole))
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($parRole) {
                foreach ($parRole[$role->name] as $name) {
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
