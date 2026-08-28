<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Dépense validée et imputée à un véhicule dont le propriétaire est
 * réellement connecté — scope phase 1 (cf. rapport notifications, 2026-08-27) :
 * seul beneficiaire_type === 'proprietaire' déclenche cette notification,
 * livreur/site/salarie/prestataire restent hors périmètre pour l'instant.
 */
class DepenseValideeNotification extends Notification
{
    public function __construct(
        private readonly string $depenseId,
        private readonly string $vehiculeNom,
        private readonly float $montant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $montantFormate = number_format($this->montant, 0, ',', ' ');

        return [
            'type' => 'depense_validee',
            'titre' => 'Dépense validée',
            'message' => "{$montantFormate} GNF — {$this->vehiculeNom}",
            'montant' => $this->montant,
            'resource' => [
                'type' => 'depense',
                'id' => $this->depenseId,
            ],
        ];
    }
}
