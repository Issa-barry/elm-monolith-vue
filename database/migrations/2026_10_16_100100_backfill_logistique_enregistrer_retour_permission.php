<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `logistique.enregistrer_retour`
 * (retour au site source d'une marchandise partie sur un transfert, ADR 0029, cf.
 * TransfertLogistiquePolicy::enregistrerRetour()) et l'accorde aux rôles qui font déjà avancer les
 * transferts : ceux ayant `logistique.update`. Sans ce backfill, aucun rôle existant ne pourrait
 * utiliser la nouvelle action tant qu'un administrateur ne l'aurait pas cochée dans
 * /backoffice/roles (le seeder ne repose plus la matrice des rôles existants, ADR 0011).
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'logistique.enregistrer_retour', 'guard_name' => 'web']);

        Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'logistique.update'))
            ->with('permissions')
            ->chunkById(200, function ($roles) {
                foreach ($roles as $role) {
                    if (! $role->permissions->contains('name', 'logistique.enregistrer_retour')) {
                        $role->givePermissionTo('logistique.enregistrer_retour');
                    }
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
