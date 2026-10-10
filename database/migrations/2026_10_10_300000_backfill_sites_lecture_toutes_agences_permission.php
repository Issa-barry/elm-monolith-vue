<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permission « consulter les données de toutes les agences » (ADR 0025).
 * Accordée une fois aux rôles dont le trinôme est `ADD` (Assistant·e de direction), quel que soit
 * leur libellé. Le trinôme ne sert qu'ici, à désigner le rôle : ensuite seule la permission compte
 * (cochable dans /backoffice/roles → Sites), jamais le trinôme ni le nom du rôle. Aucun autre rôle
 * ne la reçoit (ADR 0011). Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSION = 'sites.lecture_toutes_agences';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->where('code', 'ADD')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                if (! $role->permissions->contains('name', self::PERMISSION)) {
                    $role->givePermissionTo(self::PERMISSION);
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
