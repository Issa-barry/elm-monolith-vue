<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ce job était utilisé pour passer les parts PENDING → AVAILABLE.
 * Le statut PENDING a été supprimé : toutes les commissions sont directement
 * en statut "impaye" dès leur création. Le job est conservé pour ne pas
 * casser les schedules existants mais ne fait plus rien.
 */
class UnlockAvailableCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function handle(): void
    {
        Log::info('UnlockAvailableCommissionsJob: no-op (statut PENDING supprimé)');
    }
}
