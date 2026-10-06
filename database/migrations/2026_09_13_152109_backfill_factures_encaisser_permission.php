<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES (pas de schéma) — corollaire du branchement de la permission dédiée
 * `factures.encaisser` (13/09/2026). Jusqu'ici cette permission existait dans
 * PermissionCatalog::STANDALONE (case cochable dans /backoffice/roles) mais n'était vérifiée par
 * AUCUN code : le flag `can_encaisser` (Ventes\ShowCommandeVenteController, bouton « Encaisser »
 * de Ventes/Show.vue) s'appuyait uniquement sur `ventes.update`, et
 * Ventes\StoreEncaissementVenteController (la route réellement appelée au clic) n'avait AUCUN
 * contrôle d'autorisation propre — cf. CLAUDE.md §9, le frontend ne doit jamais être la seule
 * protection d'une validation financière.
 *
 * Sans ce backfill, TOUT rôle (système ET organisation confondus) ayant `ventes.update` mais pas
 * explicitement `factures.encaisser` perdrait d'un coup, silencieusement, la capacité
 * d'encaisser une facture — une régression bien plus grave que celle du chantier précédent
 * (backfill_ventes_workflow_permissions) puisqu'elle bloquerait l'encaissement quotidien des
 * ventes pour toute organisation n'ayant jamais eu besoin de cocher cette case (inerte jusqu'ici).
 *
 * Non destructive et idempotente : on ne fait qu'AJOUTER `factures.encaisser` aux rôles qui
 * avaient déjà `ventes.update`, jamais en retirer. Une organisation peut ensuite librement
 * décocher cette permission indépendamment via /backoffice/roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'factures.encaisser']);

        Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'ventes.update'))
            ->whereDoesntHave('permissions', fn ($q) => $q->where('name', 'factures.encaisser'))
            ->chunkById(200, function ($roles) {
                foreach ($roles as $role) {
                    $role->givePermissionTo('factures.encaisser');
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Pas de rollback de données : redescendre réintroduirait un couplage silencieux à
     * `ventes.update` dans le code (hors périmètre d'une migration) — retirer cette permission ici
     * romprait des rôles qui l'auraient depuis configurée volontairement.
     */
    public function down(): void
    {
        //
    }
};
