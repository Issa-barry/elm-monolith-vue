<?php

namespace App\Jobs;

use App\Models\TransfertLogistique;
use App\Services\ExpoPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifierLivreursTransfertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $transfertId,
        private readonly string $reference,
    ) {}

    public function handle(ExpoPushNotificationService $push): void
    {
        $transfert = TransfertLogistique::with([
            'equipeLivraison.livreurs.user',
        ])->find($this->transfertId);

        if (! $transfert?->equipeLivraison) {
            return;
        }

        $tokens = $transfert->equipeLivraison->livreurs
            ->map(fn ($livreur) => $livreur->user?->expo_push_token)
            ->filter()
            ->values()
            ->all();

        if (empty($tokens)) {
            return;
        }

        try {
            $push->sendMany(
                $tokens,
                'Nouvelle livraison assignée',
                "Réf. {$this->reference} — Touchez pour voir les détails.",
                [
                    'type' => 'transfert_created',
                    'transfert_id' => $this->transfertId,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifierLivreursTransfertJob: push échoué', [
                'transfert_id' => $this->transfertId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
