<?php

namespace App\Enums;

/**
 * Statut d'un `App\Models\MessageLog` — strictement limité à ce que le
 * fournisseur transport (Nimba) rend RÉELLEMENT observable en P1 (cf. audit
 * monitoring Communications, 07/09/2026) : `sent` signifie seulement que
 * Nimba a accepté l'envoi, jamais qu'il a été délivré au destinataire.
 * `delivered` n'existe pas ici tant que l'existence d'un webhook de statut de
 * livraison Nimba n'a pas été vérifiée (P2) — ne pas l'ajouter par
 * anticipation.
 */
enum MessageLogStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::SENT => 'Envoyé',
            self::FAILED => 'Erreur',
        };
    }
}
