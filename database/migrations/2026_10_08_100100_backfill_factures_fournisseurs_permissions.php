<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions des factures fournisseurs (ADR 0022). Matrices par défaut des
 * rôles système (ADR 0011 : jamais posées par le seeder sur un rôle existant) :
 * - admin_entreprise : toutes ;
 * - comptable : lire, saisir, modifier, valider, annuler.
 * Les rôles personnalisés ne les reçoivent pas : leur organisation les coche si elle le souhaite.
 * Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PAR_ROLE = [
        'admin_entreprise' => [
            'factures-fournisseurs.create', 'factures-fournisseurs.read', 'factures-fournisseurs.update',
            'factures-fournisseurs.delete', 'factures-fournisseurs.valider', 'factures-fournisseurs.annuler',
        ],
        'comptable' => [
            'factures-fournisseurs.create', 'factures-fournisseurs.read', 'factures-fournisseurs.update',
            'factures-fournisseurs.valider', 'factures-fournisseurs.annuler',
        ],
    ];

    public function up(): void
    {
        foreach (self::PAR_ROLE['admin_entreprise'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', array_keys(self::PAR_ROLE))
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                $manquantes = array_diff(self::PAR_ROLE[$role->name], $role->permissions->pluck('name')->all());
                if ($manquantes !== []) {
                    $role->givePermissionTo(array_values($manquantes));
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : les permissions ont pu être configurées volontairement depuis. */
    public function down(): void
    {
        //
    }
};
