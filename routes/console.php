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

// Purge les Personal Access Tokens Sanctum expirés depuis plus de 24h (cf.
// config/sanctum.php pour la politique d'expiration). Hygiène de base — un token
// expiré est déjà inutilisable avant cette purge, elle ne fait que libérer la table.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->name('sanctum-prune-expired')
    ->withoutOverlapping();
