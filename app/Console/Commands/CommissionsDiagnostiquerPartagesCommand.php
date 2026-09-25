<?php

namespace App\Console\Commands;

use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\Categorie;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Vehicule;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageLivraisonVersionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quelles équipes ne pourront plus prendre de commande ? Depuis le 24/09/2026, une commande (et
 * son chargement) est refusée dès que le partage Livreur de l'équipe du véhicule n'est pas
 * conforme pour une catégorie vendue — somme ≠ barème Livreur en vigueur, ou membre actif sans
 * part. À exécuter AVANT la mise en production de cette règle, pour corriger les équipes à
 * l'avance plutôt que de découvrir les blocages au comptoir.
 *
 * Même juge que la commande (CommissionPartageLivraisonCategorieChecker::nonConformites()) :
 * aucune règle recalculée ici. Lecture seule par défaut ; seule l'option explicite
 * `--aligner-livreur-unique` écrit : elle aligne le partage des équipes à UN SEUL livreur actif
 * sur le barème en vigueur (rattrapage de la règle du 25/09/2026). Les équipes à plusieurs
 * livreurs ne sont jamais modifiées : leur répartition reste une décision explicite.
 */
class CommissionsDiagnostiquerPartagesCommand extends Command
{
    protected $signature = 'commissions:diagnostiquer-partages
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--csv= : Exporte les non-conformités dans ce fichier CSV}
        {--aligner-livreur-unique : ÉCRIT : aligne sur le barème en vigueur le partage des équipes à un seul livreur actif}';

    protected $description = 'Diagnostic des équipes dont le partage Livreur n\'est pas conforme au barème en vigueur (commandes bloquées) ; lecture seule sauf --aligner-livreur-unique.';

    /** @var list<array<string, string|int>> */
    private array $export = [];

    public function handle(): int
    {
        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }

        $total = 0;
        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})");
            $total += $this->diagnostiquer($organization);
        }

        $this->newLine();
        $total === 0
            ? $this->info('Aucune non-conformité : aucune commande ne sera bloquée par le partage Livreur.')
            : $this->warn("{$total} non-conformité(s) : les commandes de ces véhicules sur ces catégories seront refusées tant que le partage n'est pas corrigé.");

        if ($csv = $this->option('csv')) {
            $this->exporterCsv((string) $csv);
        }

        return self::SUCCESS;
    }

    private function diagnostiquer(Organization $organization): int
    {
        $categorieIds = Categorie::where('organization_id', $organization->id)
            ->where('statut', 'actif')
            ->pluck('id')
            ->all();

        $equipes = EquipeLivraison::with('vehicule.typeVehicule')
            ->where('organization_id', $organization->id)
            ->whereHas('vehicule')
            ->get()
            ->sortBy(fn (EquipeLivraison $e) => $e->vehicule?->nom_vehicule)
            ->values();

        $lignes = [];
        foreach ($equipes as $equipe) {
            $vehicule = $equipe->vehicule;
            $codes = CommissionProcessusDefaults::codesApplicablesPourVehicule(
                $vehicule,
                CommissionRegleController::processusCodesDisponibles(),
            );

            foreach ($codes as $code) {
                $nonConformites = CommissionPartageLivraisonCategorieChecker::nonConformites(
                    $organization->id,
                    $equipe,
                    $code,
                    $vehicule->type_vehicule_id,
                    $categorieIds,
                    Carbon::today(),
                );

                $requis = CommissionPartageLivraisonCategorieChecker::membresRequis($equipe);
                if ($this->option('aligner-livreur-unique') && $requis->count() === 1 && $nonConformites->isNotEmpty()) {
                    $this->alignerLivreurUnique($organization->id, $equipe, $vehicule, $code, (string) $requis->keys()->first(), $nonConformites);

                    continue;
                }

                foreach ($nonConformites as $nc) {
                    $ligne = [
                        'organisation' => $organization->name,
                        'vehicule' => $vehicule->nom_vehicule,
                        'immatriculation' => (string) $vehicule->immatriculation,
                        'type_vehicule' => (string) ($vehicule->typeVehicule?->nom ?? ''),
                        'processus' => CommissionRegleController::processusLabel($code),
                        'categorie' => $nc['categorie_nom'],
                        'bareme' => $nc['bareme'],
                        'total_configure' => $nc['total_configure'] ?? '',
                        'ecart' => $nc['total_configure'] === null ? '' : $nc['ecart'],
                        'membres_sans_part' => implode(', ', $nc['membres_manquants']),
                        'detail' => CommissionPartageLivraisonCategorieChecker::libelleNonConformite($nc),
                    ];
                    $lignes[] = $ligne;
                    $this->export[] = $ligne;
                }
            }
        }

        if (empty($lignes)) {
            $this->line('  <fg=green>✓</> Tous les partages sont conformes.');

            return 0;
        }

        $this->table(
            ['Véhicule', 'Processus', 'Détail'],
            array_map(fn (array $l) => [$l['vehicule'], $l['processus'], $l['detail']], $lignes),
        );

        return count($lignes);
    }

    /**
     * Rattrapage de la règle « un seul livreur actif = 100 % du barème » (décision du 25/09/2026)
     * pour les équipes restées non conformes après un barème appliqué AVANT cette règle. Nouvelle
     * version de partage datée selon l'option A (date d'effet du barème qui l'a rendue nécessaire),
     * ancienne version conservée, aucune commission déjà générée touchée.
     *
     * @param  Collection<int, array<string, mixed>>  $nonConformites
     */
    private function alignerLivreurUnique(string $orgId, EquipeLivraison $equipe, Vehicule $vehicule, string $code, string $livreurId, Collection $nonConformites): void
    {
        $processus = CommissionProcessus::where('organization_id', $orgId)->where('code', $code)->firstOrFail();

        foreach ($nonConformites as $nc) {
            DB::transaction(function () use ($orgId, $equipe, $vehicule, $processus, $livreurId, $nc) {
                $versionActive = CommissionPartageLivraisonCategorieChecker::partagesActifs($processus->id, $equipe->id, $nc['categorie_id'], Carbon::today());
                $dateEffet = CommissionPartageLivraisonCategorieChecker::dateEffetNouvelleVersion(
                    $orgId, $processus->id, $nc['categorie_id'], $vehicule->type_vehicule_id, $versionActive, Carbon::today(),
                );

                PartageLivraisonVersionService::versionner($equipe->id, $processus->id, $nc['categorie_id'], [$livreurId => $nc['bareme']], $dateEffet);
            });

            $this->line(sprintf(
                '  <fg=green>✓</> %s · %s · %s : aligné sur %d GNF/pack (effet au %s).',
                $vehicule->nom_vehicule,
                CommissionRegleController::processusLabel($code),
                $nc['categorie_nom'],
                $nc['bareme'],
                Carbon::today()->toDateString(),
            ));
        }
    }

    private function exporterCsv(string $chemin): void
    {
        $handle = fopen($chemin, 'w');
        if ($handle === false) {
            $this->error("Impossible d'écrire {$chemin}.");

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Organisation', 'Véhicule', 'Immatriculation', 'Type de véhicule', 'Processus', 'Catégorie', 'Barème Livreur (GNF/pack)', 'Partage configuré (GNF/pack)', 'Écart (GNF)', 'Membres sans part', 'Détail'], ';');
        foreach ($this->export as $ligne) {
            fputcsv($handle, array_values($ligne), ';');
        }
        fclose($handle);

        $this->info(count($this->export)." ligne(s) exportée(s) dans {$chemin}.");
    }

    private function resolveOrganizations(): ?Collection
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return Organization::query()->get();
        }

        $organizations = Organization::query()
            ->where(function (Builder $q) use ($identifiants) {
                $q->whereIn('id', $identifiants)
                    ->orWhereIn('code', $identifiants)
                    ->orWhereIn('slug', $identifiants);
            })
            ->get();

        $trouves = $organizations->flatMap(fn (Organization $o) => array_filter([$o->id, $o->code, $o->slug]));
        $manquants = array_diff($identifiants, $trouves->all());
        if (! empty($manquants)) {
            $this->error('Organisation(s) introuvable(s) : '.implode(', ', $manquants));

            return null;
        }

        return $organizations;
    }
}
