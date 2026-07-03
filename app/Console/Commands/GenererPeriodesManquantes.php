<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\PeriodePaiementService;
use Illuminate\Console\Command;

class GenererPeriodesManquantes extends Command
{
    protected $signature = 'periodes:generer-manquantes {--annee= : Année à générer (par défaut, année courante)}';

    protected $description = "Génère (de façon idempotente) les périodes de paiement P1/P2 manquantes de l'année pour chaque organisation et chaque type de bénéficiaire.";

    public function handle(PeriodePaiementService $periodes): int
    {
        $annee = (int) ($this->option('annee') ?: now()->year);

        $organizations = Organization::all(['id']);
        $created = 0;

        foreach ($organizations as $organization) {
            $created += $periodes->generatePeriodsForYear($organization->id, $annee)
                ->filter(fn ($periode) => $periode->wasRecentlyCreated)
                ->count();
        }

        $this->info("✓ {$created} nouvelle(s) période(s) générée(s) pour {$annee} sur {$organizations->count()} organisation(s).");

        return Command::SUCCESS;
    }
}
