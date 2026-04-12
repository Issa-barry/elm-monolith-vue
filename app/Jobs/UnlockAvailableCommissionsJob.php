<?php

namespace App\Jobs;

use App\Enums\StatutPartCommission;
use App\Models\CommissionLogistiquePart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * À planifier quotidiennement dans App\Console\Kernel (ou via Scheduler).
 *
 * Ce job bascule les parts PENDING → AVAILABLE dès que unlock_at <= today.
 * Il est idempotent : re-exécuté plusieurs fois, le résultat est identique.
 *
 * Enregistrement dans Schedule (bootstrap/app.php ou Console/Kernel.php) :
 *   $schedule->job(UnlockAvailableCommissionsJob::class)->dailyAt('06:00');
 */
class UnlockAvailableCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(): void
    {
        $today = now()->toDateString();

        // Traitement par batch de 200 pour éviter les timeouts mémoire
        CommissionLogistiquePart::query()
            ->where('statut', StatutPartCommission::PENDING->value)
            ->whereNotNull('unlock_at')
            ->where('unlock_at', '<=', $today)
            ->chunkById(200, function ($parts) {
                foreach ($parts as $part) {
                    $part->tenterDeblocage();
                }
            });

        Log::info('UnlockAvailableCommissionsJob terminé', ['date' => $today]);
    }
}
