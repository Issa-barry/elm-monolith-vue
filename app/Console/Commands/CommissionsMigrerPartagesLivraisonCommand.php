<?php

namespace App\Console\Commands;

use App\Models\CommissionProcessus;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Organization;
use App\Services\Commission\CommissionRegleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Convertit le partage Livreur existant (part_pourcentage) vers des montants
 * GNF entiers fixes (montant_unitaire), par équipe × catégorie — jamais un
 * simple renommage de colonne : chaque groupe est résolu contre le barème
 * réellement actif à ce jour, converti déterministiquement, puis vérifié.
 *
 * Idempotente : ignore les groupes déjà migrés (au moins une ligne active
 * avec montant_unitaire non nul). N'écrit jamais un montant inventé — un
 * groupe non résolvable (barème introuvable/à 0 avec des % positifs) est
 * simplement rapporté, jamais deviné.
 */
class CommissionsMigrerPartagesLivraisonCommand extends Command
{
    protected $signature = 'commissions:migrer-partages-livraison
        {organization? : ID, code ou slug d\'organisation ; toutes si omis}
        {--dry-run : Affiche le rapport de conversion sans rien écrire}';

    protected $description = 'Convertit le partage Livreur (%) existant en montants GNF entiers fixes, par équipe × catégorie.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }

        $totalConvertis = 0;
        $totalEchecs = 0;

        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})".($dryRun ? ' [dry-run]' : ''));

            [$convertis, $echecs] = $this->migrerOrganisation($organization, $dryRun);
            $totalConvertis += $convertis;
            $totalEchecs += $echecs;
        }

        $this->newLine();
        $this->info("Groupes convertis : {$totalConvertis}");
        if ($totalEchecs > 0) {
            $this->error("Groupes non résolvables (à traiter manuellement) : {$totalEchecs}");

            return self::FAILURE;
        }

        if (! $dryRun && $totalConvertis > 0) {
            $this->info('Vérification de cohérence...');
            if (! $this->verifierCoherence($organizations)) {
                $this->error('Écart détecté après conversion — voir le détail ci-dessus.');

                return self::FAILURE;
            }
            $this->info('Cohérence vérifiée : chaque groupe migré somme exactement son barème.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} [convertis, échecs] */
    private function migrerOrganisation(Organization $org, bool $dryRun): array
    {
        $groupes = EquipeLivraisonPartageCategorie::query()
            ->whereNull('effective_to')
            ->whereNull('montant_unitaire')
            ->whereHas('equipe', fn ($q) => $q->where('organization_id', $org->id))
            ->get()
            ->groupBy(fn (EquipeLivraisonPartageCategorie $p) => "{$p->equipe_id}:{$p->categorie_id}");

        if ($groupes->isEmpty()) {
            $this->line('  Aucun groupe à convertir (déjà migré ou aucun partage configuré).');

            return [0, 0];
        }

        $processusId = CommissionProcessus::where('organization_id', $org->id)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->value('id');

        $convertis = 0;
        $echecs = 0;

        foreach ($groupes as $membres) {
            $premier = $membres->first();
            $equipeId = $premier->equipe_id;
            $categorieId = $premier->categorie_id;

            $regle = $processusId
                ? CommissionRegleResolver::resolve(
                    $org->id, $processusId, 'equipe_livraison', null, null, $categorieId, Carbon::today(),
                )
                : null;
            $bareme = (int) round((float) ($regle?->montant ?? 0));

            $sommePct = (float) $membres->sum('part_pourcentage');
            if ($bareme <= 0 && $sommePct > 0) {
                $this->warn("  ✗ équipe {$equipeId} / catégorie {$categorieId} : aucun barème Livreur actif résolu — non converti.");
                $echecs++;

                continue;
            }
            if ($bareme <= 0) {
                // % à 0 et barème à 0 : rien à convertir, groupe déjà cohérent.
                continue;
            }

            $montants = $this->convertirDeterministe($membres, $bareme);

            $this->line("  ✓ équipe {$equipeId} / catégorie {$categorieId} : barème {$bareme} GNF/unité, ".$membres->count().' membre(s) — '.
                collect($montants)->map(fn ($m, $id) => "{$m} GNF")->implode(' + '));

            if (! $dryRun) {
                DB::transaction(function () use ($membres, $montants) {
                    $maintenant = now();
                    foreach ($membres as $membre) {
                        $membre->update(['effective_to' => $maintenant]);

                        EquipeLivraisonPartageCategorie::create([
                            'equipe_id' => $membre->equipe_id,
                            'categorie_id' => $membre->categorie_id,
                            'livreur_id' => $membre->livreur_id,
                            'part_pourcentage' => 0,
                            'montant_unitaire' => $montants[$membre->livreur_id],
                            'effective_from' => $maintenant,
                        ]);
                    }
                });
            }

            $convertis++;
        }

        return [$convertis, $echecs];
    }

    /**
     * Conversion déterministe % → GNF entier : arrondi standard par membre, puis le
     * reliquat d'arrondi (positif ou négatif) est assigné au membre ayant le plus gros
     * montant (ordre stable par livreur_id) — garantit somme(montants) === barème,
     * toujours, jamais un écart silencieux (même principe que
     * CommissionRepartitionEngine::repartir(), appliqué ici à une conversion ponctuelle).
     *
     * @return array<string, int> montant par livreur_id
     */
    private function convertirDeterministe($membres, int $bareme): array
    {
        $arrondis = [];
        foreach ($membres as $membre) {
            $arrondis[$membre->livreur_id] = (int) round(((float) $membre->part_pourcentage / 100) * $bareme);
        }

        $reliquat = $bareme - array_sum($arrondis);
        if ($reliquat !== 0) {
            $cibleId = collect($arrondis)->sortDesc()->keys()->first();
            $arrondis[$cibleId] += $reliquat;
        }

        return $arrondis;
    }

    private function verifierCoherence($organizations): bool
    {
        $ok = true;
        foreach ($organizations as $org) {
            $groupes = EquipeLivraisonPartageCategorie::query()
                ->whereNull('effective_to')
                ->whereNotNull('montant_unitaire')
                ->whereHas('equipe', fn ($q) => $q->where('organization_id', $org->id))
                ->get()
                ->groupBy(fn (EquipeLivraisonPartageCategorie $p) => "{$p->equipe_id}:{$p->categorie_id}");

            $processusId = CommissionProcessus::where('organization_id', $org->id)
                ->where('code', CommissionProcessus::CODE_VENTE)
                ->value('id');

            foreach ($groupes as $cle => $membres) {
                $categorieId = $membres->first()->categorie_id;
                $regle = $processusId
                    ? CommissionRegleResolver::resolve($org->id, $processusId, 'equipe_livraison', null, null, $categorieId, Carbon::today())
                    : null;
                $bareme = (int) round((float) ($regle?->montant ?? 0));
                $somme = (int) $membres->sum('montant_unitaire');

                if ($bareme > 0 && $somme !== $bareme) {
                    $this->error("  ✗ écart persistant sur {$cle} : somme={$somme}, barème={$bareme}");
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function resolveOrganizations(): ?Collection
    {
        $identifiant = $this->argument('organization');
        if (! $identifiant) {
            return Organization::query()->get();
        }

        $organization = Organization::query()
            ->where('id', $identifiant)
            ->orWhere('code', $identifiant)
            ->orWhere('slug', $identifiant)
            ->first();

        if (! $organization) {
            $this->error("Organisation introuvable : {$identifiant}");

            return null;
        }

        return collect([$organization]);
    }
}
