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
