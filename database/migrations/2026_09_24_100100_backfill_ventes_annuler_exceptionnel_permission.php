<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `ventes.annuler_exceptionnel`
 * (annulation exceptionnelle d'une commande saisie par erreur, cf. AnnulationExceptionnelleService)
 * et l'accorde au seul rôle système `super_admin`. Aucun autre rôle ne la reçoit : c'est une
 * opération d'exception, qu'une organisation peut ensuite déléguer explicitement dans
 * /backoffice/roles si elle le souhaite.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.annuler_exceptionnel', 'guard_name' => 'web']);

        Role::query()
            ->where('name', 'super_admin')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'ventes.annuler_exceptionnel')) {
                    $role->givePermissionTo('ventes.annuler_exceptionnel');
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
