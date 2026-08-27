<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Services\ExpoPushNotificationService;
use Closure;
use Illuminate\Notifications\Notification;

/**
 * Point central d'envoi d'une notification métier : dédoublonne les
 * destinataires par user id, filtre par préférence de notification
 * (User::wantsNotification), notifie (canaux via Notification::via()), puis
 * pousse en Expo si un payload push est fourni — un seul appel groupé, pas un
 * par destinataire.
 *
 * Remplace la collecte de tokens/préférences dupliquée dans chaque
 * Job/Service (cf. audit notifications du 27/08/2026) : avant cette classe,
 * NotifierLivreursCommandeVenteJob avait sa propre logique, et
 * NotifierLivreursTransfertJob n'en avait aucune (préférences ignorées, push
 * envoyé à tout le monde sans filtre).
 */
class NotificationDispatcher
{
    /**
     * @param  iterable<int, User|null>  $recipients  Peut contenir des null (bénéficiaire
     *                                                sans compte résolu) et des doublons (même User résolu deux fois pour un même
     *                                                envoi) — les deux sont filtrés ici, jamais à la charge de l'appelant.
     * @param  (Closure(): (array{title: string, body: string, data?: array}|null))|null  $pushPayload
     *                                                                                                  Différée : n'est évaluée que s'il reste au moins un destinataire après filtrage
     *                                                                                                  préférence, pour ne jamais construire un payload push inutile.
     */
    public static function send(
        Notification $notification,
        iterable $recipients,
        string $category,
        ?Closure $pushPayload = null,
    ): void {
        $destinataires = collect($recipients)
            ->filter()
            ->unique('id')
            ->filter(fn (User $user) => $user->wantsNotification($category));

        if ($destinataires->isEmpty()) {
            return;
        }

        foreach ($destinataires as $destinataire) {
            $destinataire->notify($notification);
        }

        if (! $pushPayload) {
            return;
        }

        $payload = $pushPayload();
        if (! $payload) {
            return;
        }

        $tokens = $destinataires->pluck('expo_push_token')->filter()->unique()->values()->all();
        if (empty($tokens)) {
            return;
        }

        app(ExpoPushNotificationService::class)->sendMany(
            $tokens,
            $payload['title'],
            $payload['body'],
            $payload['data'] ?? [],
        );
    }
}
