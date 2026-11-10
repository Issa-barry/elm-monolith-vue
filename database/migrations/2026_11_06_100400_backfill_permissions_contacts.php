<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée les permissions du référentiel Contacts (ADR 0060)
 * et les accorde à `super_admin` et `admin_entreprise`. Les autres rôles les reçoivent dans
 * /backoffice/roles : aucun nom de rôle personnalisé n'est codé ici (ADR 0011). Pas de
 * `contacts.delete` : une fiche se désactive.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER, jamais retirer.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'contacts.read',
        'contacts.create',
        'contacts.update',
        'contacts.gerer_categories',
        'contacts.gerer_interlocuteurs',
    ];

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

    /**
     * Pas de rollback de données : retirer ces permissions romprait des rôles qui les auraient
     * depuis configurées volontairement.
     */
    public function down(): void
    {
        //
    }
};
