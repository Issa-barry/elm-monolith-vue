<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — permissions du dossier salarié (lot 1 RH, ADR 0011 :
 * le seeder ne repose pas la matrice des rôles existants).
 *
 * Accordées de façon à ne retirer aucun accès existant :
 * - `rh-remunerations.read` (voir les salaires) : rôles qui les voyaient déjà par `rh-employes.read`
 *   ou `rh-contrats.read`, soit admin_entreprise et manager. Retirable ensuite dans /backoffice/roles.
 * - `rh-employes.donnees_sensibles` (CNSS, coordonnées de paiement — données nouvelles) :
 *   admin_entreprise seulement.
 * - `rh-referentiels.gerer` (fonctions et services RH) : admin_entreprise, qui gérait déjà les
 *   fonctions (contrôle `admin_entreprise` codé en dur remplacé par cette permission).
 *
 * `super_admin` les a d'office (Gate::before). Non destructive et idempotente.
 */
return new class extends Migration
{
    private const OCTROIS = [
        'rh-remunerations.read' => ['super_admin', 'admin_entreprise', 'manager'],
        'rh-employes.donnees_sensibles' => ['super_admin', 'admin_entreprise'],
        'rh-referentiels.gerer' => ['super_admin', 'admin_entreprise'],
    ];

    public function up(): void
    {
        foreach (self::OCTROIS as $permission => $roles) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

            Role::query()
                ->whereIn('name', $roles)
                ->with('permissions')
                ->get()
                ->each(function (Role $role) use ($permission) {
                    if (! $role->permissions->contains('name', $permission)) {
                        $role->givePermissionTo($permission);
                    }
                });
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : ces permissions ont pu être configurées volontairement depuis. */
    public function down(): void
    {
        //
    }
};
