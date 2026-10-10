<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Événement d'un bon de commande fournisseur (ADR 0021) : créé (à valider), validé (à
 * réceptionner) ou annulé. Base de données uniquement (+ push via NotificationDispatcher) : pas
 * d'email.
 */
class CommandeAchatNotification extends Notification
{
    public function __construct(
        private readonly string $commandeId,
        private readonly string $type,
        private readonly string $titre,
        private readonly string $message,
        private readonly float $montant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'titre' => $this->titre,
            'message' => $this->message,
            'montant' => $this->montant,
            'resource' => [
                'type' => 'commande_achat',
                'id' => $this->commandeId,
            ],
        ];
    }
}
