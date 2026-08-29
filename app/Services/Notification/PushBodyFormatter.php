<?php

namespace App\Services\Notification;

/**
 * Construit le `body` du push (Expo/Web Push) à partir du payload déjà
 * normalisé d'une notification (`Notification::toArray()`) — jamais construit
 * ailleurs, jamais dupliqué par appelant (cf. rapport "nettoyer les messages
 * de notifications", 2026-08-28).
 *
 * Contexte : `message` (stocké en database, exposé tel quel par
 * NotificationResource) n'inclut plus le montant quand un champ `montant`
 * structuré existe déjà — évite la duplication visuelle titre/message/montant
 * dans la cloche/le dashboard, qui affichent déjà `montant` séparément. Le
 * push système, lui, n'a qu'un seul champ `body` — le montant doit donc y
 * réapparaître, uniquement là, jamais dans le contrat API.
 */
class PushBodyFormatter
{
    public static function format(array $notifData): string
    {
        if (! isset($notifData['montant'])) {
            return $notifData['message'];
        }

        $montantFormate = number_format((float) $notifData['montant'], 0, ',', ' ');

        return "{$montantFormate} GNF — {$notifData['message']}";
    }
}
