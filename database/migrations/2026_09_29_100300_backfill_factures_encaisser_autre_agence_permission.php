<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `factures.encaisser_autre_agence`
 * (encaisser dans son agence une commande d'une autre agence, ADR 0012) et l'accorde au rôle
 * `admin_entreprise` (décision du 29/09/2026). `super_admin` l'a d'office (Gate::before et
 * RolesAndPermissionsSeeder). Les autres rôles l'obtiennent explicitement dans /backoffice/roles :
 * `factures.encaisser` seule ne change pas de comportement.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'factures.encaisser_autre_agence', 'guard_name' => 'web']);

        Role::query()
            ->whereIn('name', ['super_admin', 'admin_entreprise'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'factures.encaisser_autre_agence')) {
                    $role->givePermissionTo('factures.encaisser_autre_agence');
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Pas de rollback de données : retirer la permission romprait des rôles qui l'auraient depuis
     * configurée volontairement.
     */
    public function down(): void
    {
        //
    }
};
