<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `ventes.regulariser_apres_encaissement`
 * (régularisation d'une vente déjà encaissée, ADR 0043) et l'accorde au seul rôle système
 * `super_admin`. Une organisation peut ensuite la déléguer dans /backoffice/roles.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.regulariser_apres_encaissement', 'guard_name' => 'web']);

        Role::query()
            ->where('name', 'super_admin')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'ventes.regulariser_apres_encaissement')) {
                    $role->givePermissionTo('ventes.regulariser_apres_encaissement');
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
