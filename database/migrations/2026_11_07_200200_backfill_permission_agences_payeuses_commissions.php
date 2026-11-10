<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `commissions.configurer_agences_payeuses`
 * (Paramètres → Agences payeuses, ADR 0061) et l'accorde au seul rôle système `super_admin`. Une
 * organisation peut ensuite la déléguer dans /backoffice/roles.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'commissions.configurer_agences_payeuses', 'guard_name' => 'web']);

        Role::query()
            ->where('name', 'super_admin')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'commissions.configurer_agences_payeuses')) {
                    $role->givePermissionTo('commissions.configurer_agences_payeuses');
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : des rôles ont pu la recevoir volontairement depuis. */
    public function down(): void
    {
        //
    }
};
