<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Transfert logistique créé, équipe de livraison affectée. Jusqu'à la phase 1
 * de l'architecture notifications (2026-08-27), seul un push Expo direct
 * existait pour cet événement — aucune trace en base (absente de la cloche
 * GET /v1/mobile/notifications), aucune préférence consultée. Cette classe
 * comble ce manque, sur le même modèle que CommandeValideeNotification.
 */
class TransfertCreeNotification extends Notification
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
            'type' => 'transfert_cree',
            'titre' => 'Nouvelle livraison assignée',
            'message' => "Réf. {$this->reference} — Touchez pour voir les détails.",
            'resource' => [
                'type' => 'transfert_logistique',
                'id' => $this->transfertId,
            ],
        ];
    }
}
