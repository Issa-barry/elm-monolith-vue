<?php

namespace App\Console\Commands;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementPeriode;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\PeriodeCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage ponctuel de la validation automatique (ADR 0008) : les commissions
 * propriétaire/site/consultant générées avant la règle sont encore « créées » et non
 * validées. Cette commande leur applique la même validation système qu'à la génération,
 * puis laisse chaque période concernée passer automatiquement à « Validée » si toutes ses
 * commissions le sont (mêmes contrôles que le bouton, cf. PeriodeValidationService).
 *
 * --dry-run : n'écrit rien, affiche ce qui serait validé.
 */
class CommissionsValiderBeneficiaireUniqueCommand extends Command
{
    protected $signature = 'commissions:valider-beneficiaire-unique
        {--dry-run : Affiche ce qui serait validé sans rien modifier}';

    protected $description = 'Valide les commissions propriétaire/site/consultant encore en attente (bénéficiaire unique) et valide automatiquement les périodes devenues complètes.';

    public function handle(PeriodeCalculatorService $calculator): int
    {
        $parts = CommissionEnveloppePart::query()
            ->whereIn('beneficiaire_type', CommissionEnveloppeGenerator::TYPES_VALIDATION_AUTOMATIQUE)
            ->where('statut', StatutCommission::CREEE->value)
            ->whereNull('validated_at')
            ->with('enveloppe')
            ->get()
            ->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe !== null);

        if ($parts->isEmpty()) {
            $this->info('Aucune commission à valider.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($parts->groupBy(fn (CommissionEnveloppePart $p) => $p->enveloppe->organization_id) as $orgId => $partsOrg) {
            $this->newLine();
            $this->line("<fg=cyan>▸ Organisation {$orgId}</>");

            foreach ($partsOrg->groupBy('beneficiaire_type') as $type => $partsType) {
                $this->line(sprintf('  %-12s %3d commission(s)', $type, $partsType->count()));
            }

            $dates = $partsOrg->map(fn (CommissionEnveloppePart $p) => $p->enveloppe->earned_at)->filter();

            if ($dryRun) {
                $this->line('  Périodes concernées :');
                foreach ($this->periodesCouvrant($orgId, $dates) as $periode) {
                    $this->line("    {$periode->reference} ({$periode->statut->label()})");
                }

                continue;
            }

            DB::transaction(function () use ($partsOrg) {
                CommissionEnveloppePart::whereIn('id', $partsOrg->pluck('id'))
                    ->whereNull('validated_at')
                    ->update(['validated_at' => now()]);
            });

            $calculator->traiterPeriodesPourDates($orgId, $dates);

            $this->line('  Périodes concernées après traitement :');
            foreach ($this->periodesCouvrant($orgId, $dates) as $periode) {
                $this->line("    {$periode->reference} ({$periode->statut->label()})");
            }
        }

        $this->newLine();
        $dryRun
            ? $this->warn("Simulation : {$parts->count()} commission(s) seraient validées. Relancez sans --dry-run pour appliquer.")
            : $this->info("{$parts->count()} commission(s) validées.");

        return self::SUCCESS;
    }

    /** @return Collection<int, PaiementPeriode> */
    private function periodesCouvrant(string $orgId, Collection $dates): Collection
    {
        $jours = $dates->map(fn ($d) => Carbon::parse($d)->toDateString())->unique();

        return PaiementPeriode::where('organization_id', $orgId)
            ->whereIn('type', ['proprietaire', 'site', 'consultant'])
            ->where(function ($q) use ($jours) {
                foreach ($jours as $jour) {
                    $q->orWhere(fn ($w) => $w->whereDate('date_debut', '<=', $jour)->whereDate('date_fin', '>=', $jour));
                }
            })
            ->orderBy('type')
            ->orderBy('date_debut')
            ->get();
    }
}
