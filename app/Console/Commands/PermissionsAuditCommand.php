<?php

namespace App\Console\Commands;

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Purement informatif — n'écrit jamais en base (cf. plan de refonte rôles/permissions § 9,
 * "nettoyage de la base"). La suppression d'une permission orpheline reste une décision
 * humaine : elle peut être encore référencée par du code métier qui ne l'attache à aucun rôle
 * par défaut (permission accordée au cas par cas via un futur écran, permission legacy dont la
 * suppression casserait un `$user->can(...)` resté dans le code).
 */
class PermissionsAuditCommand extends Command
{
    protected $signature = 'permissions:audit';

    protected $description = 'Compare les permissions en base à App\\Support\\Permissions\\PermissionCatalog (rapport seul, ne modifie rien).';

    public function handle(): int
    {
        $inCatalog = collect(PermissionCatalog::allPermissionNames());
        $inDatabase = Permission::pluck('name');

        $orphelines = $inDatabase->diff($inCatalog)->values();
        $manquantes = $inCatalog->diff($inDatabase)->values();

        $nonAttachees = Permission::doesntHave('roles')->pluck('name')->values();

        $this->info('Permissions dans PermissionCatalog : '.$inCatalog->count());
        $this->info('Permissions en base : '.$inDatabase->count());
        $this->newLine();

        if ($orphelines->isNotEmpty()) {
            $this->warn("En base mais absentes de PermissionCatalog ({$orphelines->count()}) — obsolètes ou renommées :");
            $this->listWithColumns($orphelines);
        } else {
            $this->info('Aucune permission orpheline (toutes les permissions en base sont dans PermissionCatalog).');
        }

        $this->newLine();

        if ($manquantes->isNotEmpty()) {
            $this->warn("Dans PermissionCatalog mais absentes de la base ({$manquantes->count()}) — à créer via RolesAndPermissionsSeeder :");
            $this->listWithColumns($manquantes);
        } else {
            $this->info('Aucune permission manquante (le catalogue est entièrement seedé).');
        }

        $this->newLine();

        if ($nonAttachees->isNotEmpty()) {
            $this->warn("Non attachées à un seul rôle ({$nonAttachees->count()}) — probablement mortes, à vérifier avant suppression :");
            $this->listWithColumns($nonAttachees);
        } else {
            $this->info('Toutes les permissions sont attachées à au moins un rôle.');
        }

        $this->newLine();
        $this->line('Rapport seul — aucune donnée modifiée. Une suppression éventuelle reste une décision humaine.');

        return self::SUCCESS;
    }

    private function listWithColumns(Collection $names): void
    {
        $this->table(['Permission'], $names->map(fn (string $n) => [$n])->all());
    }
}
