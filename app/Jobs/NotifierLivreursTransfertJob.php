<?php

namespace App\Jobs;

use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Notifications\TransfertCreeNotification;
use App\Services\Communications\TransactionalCommunicationDispatcher;
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
 *
 * Depuis le 07/09/2026 (cf. rapport notifications de commande), déclenche
 * AUSSI les notifications transactionnelles SMS/WhatsApp configurables pour
 * l'événement `transfert_cree` (cf. TransactionalCommunicationDispatcher) —
 * même point d'accroche métier que le push ci-dessus. Aucun destinataire
 * client ici : TransfertLogistique n'a pas de client (mouvement inter-sites).
 */
class NotifierLivreursTransfertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $transfertId,
        private readonly string $reference,
    ) {}

    public function handle(TransactionalCommunicationDispatcher $communications): void
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

        foreach ($livreurs as $livreur) {
            $communications->notifierLivreur(
                CommunicationModule::LOGISTIQUE,
                CommunicationEvent::TRANSFERT_CREE,
                $livreur,
                $transfert,
                $this->reference,
            );
        }
    }
}
