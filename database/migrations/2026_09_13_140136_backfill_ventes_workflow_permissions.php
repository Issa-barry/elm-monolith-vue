<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — corollaire de la séparation des permissions de workflow
 * vente dans CommandeVentePolicy (2026-09-13) : demarrerChargement()/validerChargement()/
 * validerReception() ne vérifient plus `ventes.update` mais chacune sa propre permission dédiée
 * (`ventes.demarrer_chargement` et `ventes.valider_chargement` existaient déjà dans
 * PermissionCatalog::STANDALONE mais n'étaient vérifiées nulle part ; `ventes.valider_reception`
 * est créée par cette migration).
 *
 * Sans ce backfill, TOUT rôle (rôles système ET rôles d'organisation confondus, cf.
 * RolesAndPermissionsSeeder qui ne gère que les rôles système `organization_id IS NULL`) ayant
 * `ventes.update` mais pas explicitement l'une de ces trois permissions perdrait d'un coup,
 * silencieusement, la capacité de démarrer un chargement / le valider / valider une réception —
 * alors que jusqu'ici `ventes.update` suffisait pour les trois.
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER les permissions manquantes aux rôles qui
 * avaient déjà `ventes.update`, jamais en retirer. Une organisation peut ensuite librement décocher
 * l'une des trois indépendamment via /backoffice/roles (section « Permissions spécifiques »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.valider_reception']);

        $aBackfiller = [
            'ventes.demarrer_chargement',
            'ventes.valider_chargement',
            'ventes.valider_reception',
        ];

        Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'ventes.update'))
            ->with('permissions')
            ->chunkById(200, function ($roles) use ($aBackfiller) {
                foreach ($roles as $role) {
                    $manquants = array_diff($aBackfiller, $role->permissions->pluck('name')->all());

                    if ($manquants !== []) {
                        $role->givePermissionTo($manquants);
                    }
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Pas de rollback de données : redescendre réintroduirait un couplage silencieux à
     * `ventes.update` dans le code (hors périmètre d'une migration) — retirer ces permissions ici
     * romprait des rôles qui les auraient depuis configurées volontairement.
     */
    public function down(): void
    {
        //
    }
};
