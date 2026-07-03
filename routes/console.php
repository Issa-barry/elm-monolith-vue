<?php

use App\Jobs\UnlockAvailableCommissionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Débloque quotidiennement les commissions logistiques dont unlock_at est atteint
Schedule::job(UnlockAvailableCommissionsJob::class)
    ->dailyAt('06:00')
    ->name('unlock-commissions')
    ->withoutOverlapping();

// Filet de sécurité : les périodes de paiement sont normalement créées à la volée dès
// qu'elles sont consultées (cf. PeriodePaiementService), mais on les génère aussi en
// avance chaque jour pour que le cycle courant existe toujours sans dépendre d'une visite.
Schedule::command('periodes:generer-manquantes')
    ->dailyAt('00:05')
    ->name('generer-periodes')
    ->withoutOverlapping();
