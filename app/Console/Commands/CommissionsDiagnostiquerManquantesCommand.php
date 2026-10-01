<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Commission\CommissionMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Version console de Commissions → Monitoring (même service, mêmes statuts) : liste les
 * commissions attendues mais non générées, historique compris — toute génération en échec
 * depuis la création de commission_generation_attempts y figure déjà, rien à importer.
 *
 * Lecture seule : ne crée jamais de commission ni de tentative. La relance se fait depuis
 * l'écran Monitoring (ou la fiche commande). Une opération dont la génération n'a jamais été
 * déclenchée n'a pas de tentative : cf. commissions:auditer-ventes.
 */
class CommissionsDiagnostiquerManquantesCommand extends Command
{
    protected $signature = 'commissions:diagnostiquer-manquantes
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--tous : inclut les anomalies régularisées et sans objet}';

    protected $description = 'Liste les commissions attendues mais non générées (lecture seule, même source que Commissions > Monitoring).';

    public function handle(CommissionMonitoringService $monitoring): int
    {
        $identifiants = $this->option('organization');
        $organizations = Organization::query()
            ->when(! empty($identifiants), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('id', $identifiants)->orWhereIn('code', $identifiants)->orWhereIn('slug', $identifiants)))
            ->get();

        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        $ouvertesGlobal = 0;

        foreach ($organizations as $organization) {
            $anomalies = $monitoring->anomalies($organization->id);
            $ouvertes = $anomalies->where('ouverte', true);
            $ouvertesGlobal += $ouvertes->count();
            $affichees = $this->option('tous') ? $anomalies : $ouvertes;

            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> — {$ouvertes->count()} ouverte(s), {$anomalies->where('statut', 'regularisee')->count()} régularisée(s)");

            if ($affichees->isEmpty()) {
                continue;
            }

            $this->table(
                ['Opération', 'Date', 'Cible', 'Montant attendu', 'Statut', 'Tentatives', 'Motif'],
                $affichees->map(fn (array $a) => [
                    $a['reference'],
                    $a['date_operation'] ?? '—',
                    $a['cible_label'],
                    $a['montant_attendu'] === null ? '—' : number_format($a['montant_attendu'], 0, ',', ' ').' GNF',
                    $a['statut_label'],
                    $a['nb_tentatives_echouees'],
                    $a['message'],
                ])->all(),
            );
        }

        $this->newLine();
        if ($ouvertesGlobal > 0) {
            $this->warn("{$ouvertesGlobal} commission(s) attendue(s) non générée(s) : corrigez la configuration puis relancez depuis Commissions > Monitoring.");

            return self::FAILURE;
        }

        $this->info('Aucune commission attendue en attente de génération.');

        return self::SUCCESS;
    }
}
