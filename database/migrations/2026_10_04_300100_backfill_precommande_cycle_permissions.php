<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions du cycle de vie des précommandes (ADR 0019, lot 2) : préparer,
 * valider le retrait, rembourser, annuler avant préparation et annuler après préparation (procédure
 * renforcée, décision D1). Accordées aux rôles système `admin_entreprise` et `manager` (décision D12 ;
 * `commerciale` garde seulement `ventes.precommander`). Les rôles personnalisés ne les reçoivent pas :
 * leur organisation les coche si elle le souhaite (ADR 0011).
 *
 * Non destructive et idempotente : les permissions sont seulement ajoutées, jamais retirées.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ventes.preparer',
        'ventes.valider_retrait',
        'ventes.rembourser',
        'ventes.annuler_precommande',
        'ventes.annuler_precommande_preparee',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', ['admin_entreprise', 'manager'])
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                $manquantes = array_diff(self::PERMISSIONS, $role->permissions->pluck('name')->all());
                if ($manquantes !== []) {
                    $role->givePermissionTo($manquantes);
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
