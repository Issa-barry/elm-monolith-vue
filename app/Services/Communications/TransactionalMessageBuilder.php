<?php

namespace App\Services\Communications;

use App\Enums\CommunicationEvent;
use App\Enums\CommunicationRecipientType;

/**
 * Textes des notifications transactionnelles — en dur pour cette étape (cf.
 * rapport notifications de commande, 07/09/2026, point "pas d'éditeur de
 * templates" : viendra en P2, `communication_templates`). Jamais consulté par
 * App\Services\OtpService ni les Mailables OTP (OtpCodeMail...) : ce
 * générateur ne connaît QUE les événements de App\Enums\CommunicationEvent.
 */
class TransactionalMessageBuilder
{
    public static function build(CommunicationEvent $event, CommunicationRecipientType $recipient, string $reference): string
    {
        return match (true) {
            $event === CommunicationEvent::COMMANDE_CONFIRMEE && $recipient === CommunicationRecipientType::LIVREUR => "Eau La Maman — Nouvelle livraison assignée : commande {$reference} confirmée.",
            $event === CommunicationEvent::CHARGEMENT_VALIDE && $recipient === CommunicationRecipientType::LIVREUR => "Eau La Maman — Chargement validé pour la commande {$reference}, vous pouvez démarrer la livraison.",
            $event === CommunicationEvent::CHARGEMENT_VALIDE && $recipient === CommunicationRecipientType::CLIENT => "Eau La Maman — Votre commande {$reference} est en cours de chargement.",
            $event === CommunicationEvent::TRANSFERT_CREE && $recipient === CommunicationRecipientType::LIVREUR => "Eau La Maman — Nouveau transfert {$reference} assigné.",
            default => "Eau La Maman — Mise à jour de la commande {$reference}.",
        };
    }
}
