<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — décision du 27/09/2026 (ADR 0001) : le manager déclenche,
 * confirme ET conteste les versements de sa responsabilité. Le rôle système `manager` reçoit donc
 * `tresorerie.rejeter` (bouton « Contester »). Les rôles personnalisés ne sont pas touchés.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER, jamais retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'tresorerie.rejeter', 'guard_name' => 'web']);

        Role::query()
            ->whereNull('organization_id')
            ->where('name', 'manager')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'tresorerie.rejeter')) {
                    $role->givePermissionTo('tresorerie.rejeter');
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
