<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Commission (vente ou logistique) générée avec succès pour un bénéficiaire
 * réellement connecté (proprietaire ou livreur, cf. BeneficiaireUserResolver).
 * `site`/`consultant` n'ont aucun compte utilisateur et ne déclenchent jamais
 * cette notification (cf. rapport notifications phase 1, 2026-08-27).
 */
class CommissionGenereeNotification extends Notification
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $resourceId,
        private readonly string $reference,
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
            'type' => 'commission_generee',
            'titre' => 'Commission générée',
            'message' => "{$montantFormate} GNF — Réf. {$this->reference}",
            'montant' => $this->montant,
            'resource' => [
                'type' => $this->resourceType,
                'id' => $this->resourceId,
            ],
        ];
    }
}
