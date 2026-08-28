<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Réactivée phase 1 archi notifications (2026-08-27) — jusque-là définie mais
 * jamais dispatchée (constat audit du 26/08/2026). Couvre les 3 chemins réels
 * de paiement d'une commission (cf. rapport) : paiement direct logistique
 * (CommissionPaymentService), versement legacy logistique
 * (CommissionLogistiqueService::verser), paiement de fiche vente/logistique
 * (PaiementFichePaiementController) — $resourceType/$resourceId identifient
 * lequel des trois a déclenché l'envoi.
 */
class CommissionPayeeNotification extends Notification
{
    public function __construct(
        private readonly float $montant,
        private readonly string $modePaiement,
        private readonly ?string $note = null,
        private readonly ?string $resourceType = null,
        private readonly ?string $resourceId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $montantFormate = number_format($this->montant, 0, ',', ' ');

        return [
            'type' => 'commission_payee',
            'titre' => 'Commission reçue',
            'message' => "{$montantFormate} GNF versé ({$this->modePaiement})",
            'montant' => $this->montant,
            'mode_paiement' => $this->modePaiement,
            'note' => $this->note,
            'resource' => $this->resourceType && $this->resourceId ? [
                'type' => $this->resourceType,
                'id' => $this->resourceId,
            ] : null,
        ];
    }
}
