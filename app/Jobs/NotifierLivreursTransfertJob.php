<?php

namespace App\Jobs;

use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Notifications\TransfertCreeNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Depuis la phase 1 de l'architecture notifications (2026-08-27, cf.
 * rapport), ce job rejoint le même pattern que
 * NotifierLivreursCommandeVenteJob : notification database + respect de
 * notification_preferences via NotificationDispatcher. Avant ce correctif,
 * seul un push Expo était envoyé — aucune trace dans la cloche
 * (GET /v1/mobile/notifications), aucune préférence jamais consultée.
 */
class NotifierLivreursTransfertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $transfertId,
        private readonly string $reference,
    ) {}

    public function handle(): void
    {
        $transfert = TransfertLogistique::with(['equipeLivraison.livreurs'])->find($this->transfertId);

        if (! $transfert?->equipeLivraison) {
            return;
        }

        $notif = new TransfertCreeNotification($this->transfertId, $this->reference);

        $livreurs = $transfert->equipeLivraison->livreurs ?? collect();
        $users = $livreurs->map(fn (Livreur $livreur) => BeneficiaireUserResolver::resolve('livreur', $livreur->id));

        NotificationDispatcher::send(
            $notif,
            $users,
            'livraisons',
            fn () => [
                'title' => 'Nouvelle livraison assignée',
                'body' => "Réf. {$this->reference} — Touchez pour voir les détails.",
                'data' => ['type' => 'transfert_created', 'transfert_id' => $this->transfertId],
            ],
        );
    }
}
