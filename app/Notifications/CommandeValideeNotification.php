<?php

namespace App\Notifications;

use App\Models\CommandeVente;
use Illuminate\Notifications\Notification;

class CommandeValideeNotification extends Notification
{
    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
        private readonly string $siteNom,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'commande_validee',
            'titre'       => 'Nouvelle commande assignée',
            'message'     => "Réf. {$this->reference} — {$this->siteNom}",
            'commande_id' => $this->commandeId,
            'reference'   => $this->reference,
        ];
    }
}
