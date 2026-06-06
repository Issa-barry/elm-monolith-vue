<?php

namespace App\Jobs;

use App\Models\CommandeVente;
use App\Services\ExpoPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifierLivreursCommandeVenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
    ) {}

    public function handle(ExpoPushNotificationService $push): void
    {
        $commande = CommandeVente::with([
            'vehicule.equipe.livreurs.user',
        ])->find($this->commandeId);

        if (! $commande?->vehicule?->equipe) {
            return;
        }

        $tokens = $commande->vehicule->equipe->livreurs
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
                'Nouvelle commande assignée',
                "Réf. {$this->reference} — Vous avez une livraison à effectuer.",
                [
                    'type' => 'commande_vente_validee',
                    'commande_id' => $this->commandeId,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifierLivreursCommandeVenteJob: push échoué', [
                'commande_id' => $this->commandeId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
