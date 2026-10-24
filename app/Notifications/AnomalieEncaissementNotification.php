<?php

namespace App\Notifications;

use App\Models\AnomalieEncaissement;
use App\Services\Ventes\ControleEncaissements\DetecteurAnomaliesEncaissement;
use Illuminate\Notifications\Notification;

/**
 * Alerte du contrôle des encaissements dans l'application (cloche) : une par anomalie et par
 * destinataire. Toujours envoyée, quelles que soient les préférences de notification : c'est un
 * contrôle financier (décision du 24/10/2026, ADR 0036). L'e-mail part séparément
 * (AnomaliesEncaissementEmailNotification), regroupé par destinataire.
 */
class AnomalieEncaissementNotification extends Notification
{
    public function __construct(private readonly AnomalieEncaissement $anomalie, private readonly bool $relance = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $a = $this->anomalie;
        $reference = $a->details['commande_reference'] ?? ($a->details['reference'] ?? ($a->details['piece_numero'] ?? '—'));
        $surplus = (float) $a->surplus > 0 ? ' — surplus '.DetecteurAnomaliesEncaissement::gnf((float) $a->surplus) : '';

        return [
            'type' => 'anomalie_encaissement',
            'anomalie_id' => $a->id,
            'gravite' => $a->gravite->value,
            'titre' => ($this->relance ? 'Relance — ' : '').$a->type->label(),
            'message' => ($this->relance ? 'Relance : ' : '').$a->type->label().' — '.$reference.$surplus,
            'reference' => $reference,
            'url' => '/backoffice/comptabilite/tresorerie/controle-encaissements/'.$a->id,
        ];
    }
}
