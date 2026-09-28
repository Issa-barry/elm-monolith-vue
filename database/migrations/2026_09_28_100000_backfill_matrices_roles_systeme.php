<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — corollaire du 28/09/2026 : RolesAndPermissionsSeeder ne
 * resynchronise plus les rôles système configurables à chaque déploiement (il écrasait les
 * permissions cochées dans /backoffice/roles), il ne pose leur matrice qu'à la création du rôle.
 *
 * Les permissions ajoutées aux matrices par défaut depuis la dernière mise en production (août
 * 2026) comptaient encore sur cette resynchronisation pour atteindre les rôles existants : elles
 * sont rattrapées ici, rôle système par rôle système. Les rôles personnalisés ne sont pas touchés.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER, jamais retirer.
 */
return new class extends Migration
{
    private const AJOUTS = [
        'admin_entreprise' => [
            'imports-vehicules-maj.create', 'imports-vehicules-maj.read',
            'ventes.valider_reception', 'ventes.enregistrer_retour', 'ventes.exporter',
            'tresorerie.verser', 'tresorerie.valider_supports',
            'rapports.read_own', 'rapports.read',
            'communications.read', 'communications.manage',
        ],
        'manager' => [
            'imports-vehicules-maj.create', 'imports-vehicules-maj.read',
            'ventes.valider_reception', 'ventes.enregistrer_retour', 'ventes.exporter',
            'tresorerie.verser', 'tresorerie.rejeter',
            'rapports.read_own', 'rapports.read',
            'communications.read',
        ],
        'commerciale' => [
            'rapports.read_own',
        ],
        'comptable' => [
            'ventes.exporter',
            'tresorerie.verser', 'tresorerie.valider_supports',
            'rapports.read_own', 'rapports.read',
        ],
    ];

    public function up(): void
    {
        foreach (array_unique(array_merge(...array_values(self::AJOUTS))) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereNull('organization_id')
            ->whereIn('name', array_keys(self::AJOUTS))
            ->with('permissions')
            ->get()
            ->each(function (Role $role) {
                $manquantes = array_values(array_diff(self::AJOUTS[$role->name], $role->permissions->pluck('name')->all()));

                if ($manquantes !== []) {
                    $role->givePermissionTo($manquantes);
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
