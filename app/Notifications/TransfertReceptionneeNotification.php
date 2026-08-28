<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * "Livraison terminée" pour le propriétaire du véhicule — déclenchée à la
 * réception validée d'un transfert logistique (TRANSIT → RECEPTION), jamais
 * au livreur qui vient lui-même d'effectuer l'action (cf. rapport
 * notifications phase 1, 2026-08-27).
 */
class TransfertReceptionneeNotification extends Notification
{
    public function __construct(
        private readonly string $transfertId,
        private readonly string $reference,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'transfert_receptionne',
            'titre' => 'Livraison réceptionnée',
            'message' => "Réf. {$this->reference} — réception validée.",
            'resource' => [
                'type' => 'transfert_logistique',
                'id' => $this->transfertId,
            ],
        ];
    }
}
