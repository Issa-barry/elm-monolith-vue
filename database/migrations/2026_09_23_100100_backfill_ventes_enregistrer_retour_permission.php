<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — crée la permission `ventes.enregistrer_retour`
 * (enregistrement d'un retour de livraison avant encaissement, cf. CommandeVentePolicy::
 * enregistrerRetour()) et l'accorde aux rôles qui constatent déjà ce qui a été réellement livré :
 * ceux ayant `ventes.valider_reception`. Sans ce backfill, aucun rôle existant (système ou
 * personnalisé) ne pourrait utiliser la nouvelle action tant qu'un administrateur ne l'aurait pas
 * cochée à la main dans /backoffice/roles.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER la permission, jamais en retirer. Une
 * organisation peut ensuite la décocher librement rôle par rôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.enregistrer_retour', 'guard_name' => 'web']);

        Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'ventes.valider_reception'))
            ->with('permissions')
            ->chunkById(200, function ($roles) {
                foreach ($roles as $role) {
                    if (! $role->permissions->contains('name', 'ventes.enregistrer_retour')) {
                        $role->givePermissionTo('ventes.enregistrer_retour');
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
