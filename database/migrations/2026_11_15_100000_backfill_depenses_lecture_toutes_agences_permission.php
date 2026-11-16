<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permission « Dépenses — consulter les dépenses de toutes les agences »
 * (ADR 0065). Valeur de départ accordée une fois aux rôles qui consultaient déjà toutes les agences :
 * super_admin, admin_entreprise, superviseur (nom technique) et les rôles de trinôme `ADD`
 * (Assistant·e de direction, comme l'ADR 0025). Ensuite seule la case compte (/backoffice/roles →
 * Dépenses), jamais le nom ni le trinôme. Non destructive et idempotente.
 */
return new class extends Migration
{
    private const PERMISSION = 'depenses.lecture_toutes_agences';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->where(fn ($q) => $q
                ->whereIn('name', ['super_admin', 'admin_entreprise', 'superviseur'])
                ->orWhere('code', 'ADD'))
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
