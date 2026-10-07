<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions du circuit Achats refondu (ADR 0021).
 *
 * - `achats.valider` (nouvelle action) : accordée au seul rôle système `admin_entreprise`. Elle ne
 *   suffit pas : le montant doit aussi tenir dans le plafond du rôle (Paramètres → Achats), et
 *   aucune règle de plafond n'est créée ici.
 * - Annuler et réceptionner une commande dépendaient jusqu'ici de `achats.update`. Elles passent à
 *   `achats.annuler` et `receptions.create` : tout rôle (système ou personnalisé) qui avait
 *   `achats.update` les reçoit, pour ne retirer aucun droit existant.
 *
 * Non destructive et idempotente : les permissions sont seulement ajoutées, jamais retirées.
 */
return new class extends Migration
{
    private const NOUVELLES = ['achats.valider', 'achats.annuler'];

    private const HERITEES_DE_ACHATS_UPDATE = ['achats.annuler', 'receptions.create', 'receptions.read'];

    public function up(): void
    {
        foreach ([...self::NOUVELLES, 'receptions.create', 'receptions.read'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::query()
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                $actuelles = $role->permissions->pluck('name')->all();
                $aAccorder = [];

                if (in_array('achats.update', $actuelles, true)) {
                    $aAccorder = self::HERITEES_DE_ACHATS_UPDATE;
                }
                if ($role->organization_id === null && $role->name === 'admin_entreprise') {
                    $aAccorder[] = 'achats.valider';
                }

                $manquantes = array_values(array_diff(array_unique($aAccorder), $actuelles));
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
