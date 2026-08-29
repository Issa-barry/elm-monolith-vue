<?php

namespace App\Services\Notification;

use App\Jobs\DispatchPushNotificationsJob;
use App\Models\User;
use Closure;
use Illuminate\Notifications\Notification;

/**
 * Point central d'envoi d'une notification métier : dédoublonne les
 * destinataires par user id, filtre par préférence de notification
 * (User::wantsNotification), notifie (canaux via Notification::via()), puis
 * délègue le fan-out push (Expo + Web Push) à DispatchPushNotificationsJob
 * (asynchrone) si un payload push est fourni — un seul job, pas un appel par
 * destinataire ni par canal.
 *
 * Remplace la collecte de tokens/préférences dupliquée dans chaque
 * Job/Service (cf. audit notifications du 27/08/2026) : avant cette classe,
 * NotifierLivreursCommandeVenteJob avait sa propre logique, et
 * NotifierLivreursTransfertJob n'en avait aucune (préférences ignorées, push
 * envoyé à tout le monde sans filtre). Le Web Push PWA (2026-08-28) et le
 * passage d'Expo en asynchrone réutilisent ce même point central, sans
 * qu'aucun des 7 appelants métier n'ait à changer.
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

        // Asynchrone (cf. DispatchPushNotificationsJob) : un appel réseau Expo/Web Push ne
        // doit jamais faire attendre la transaction métier qui a déclenché cette notification.
        DispatchPushNotificationsJob::dispatch($destinataires->pluck('id')->all(), $payload);
    }
}
