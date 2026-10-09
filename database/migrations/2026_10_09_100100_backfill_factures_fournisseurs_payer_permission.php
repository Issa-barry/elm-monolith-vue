<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permission de paiement des factures fournisseurs (ADR 0024), accordée aux
 * rôles système admin_entreprise et comptable (mêmes rôles que commissions.payer). Les rôles
 * personnalisés ne la reçoivent pas (ADR 0011). Non destructive et idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'factures-fournisseurs.payer', 'guard_name' => 'web']);

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', ['admin_entreprise', 'comptable'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', 'factures-fournisseurs.payer')) {
                    $role->givePermissionTo('factures-fournisseurs.payer');
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
