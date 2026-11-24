<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions des absences des salariés (docs/rh-absences.md, ADR 0070).
 * Valeur de départ accordée une fois : toutes aux administrateurs (super_admin, admin_entreprise,
 * qui portent la gestion RH) et au rôle manager (responsable d'agence, limité à ses agences par
 * PerimetreEmploye). Ensuite seules les cases de /backoffice/roles → RH & Paie → Absences comptent.
 * Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'rh-absences.read',
        'rh-absences.create',
        'rh-absences.update',
        'rh-absences.annuler',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereIn('name', ['super_admin', 'admin_entreprise', 'manager'])
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
