<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CommissionPayeeNotification extends Notification
{
    public function __construct(
        private readonly float $montant,
        private readonly string $modePaiement,
        private readonly ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $montantFormate = number_format($this->montant, 0, ',', ' ');

        return [
            'type'          => 'commission_payee',
            'titre'         => 'Commission reçue',
            'message'       => "{$montantFormate} GNF versé ({$this->modePaiement})",
            'montant'       => $this->montant,
            'mode_paiement' => $this->modePaiement,
            'note'          => $this->note,
        ];
    }
}
