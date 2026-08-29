<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ExpoPushNotificationService;
use App\Services\Notification\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fan-out des canaux "push externes" (Expo + Web Push) déclenché par
 * NotificationDispatcher — jamais appelé directement par une classe métier.
 * Asynchrone (`ShouldQueue`) : un événement métier (paiement, validation...)
 * ne doit jamais attendre les appels réseau vers Expo/les fournisseurs Web
 * Push (cf. rapport Web Push, 2026-08-28). Reçoit des IDs + un tableau de
 * payload sérialisables — jamais des modèles complets ni une Closure — et
 * recharge les `User` frais à l'exécution (préférences/tokens/abonnements
 * à jour au moment de l'envoi réel, pas au moment du dispatch).
 *
 * Pas de `$afterCommit` : le driver `database` insère la ligne `jobs` dans
 * la même transaction que le reste (invisible à un worker externe avant
 * commit de toute façon) — `afterCommit` casserait silencieusement les tests
 * `RefreshDatabase`, qui enveloppent chaque test dans une transaction jamais
 * réellement commitée.
 */
class DispatchPushNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $userIds
     * @param  array{title: string, body: string, data?: array}  $payload
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $payload,
    ) {}

    public function handle(ExpoPushNotificationService $expo, WebPushService $webPush): void
    {
        $users = User::whereIn('id', $this->userIds)->get();
        if ($users->isEmpty()) {
            return;
        }

        $tokens = $users->pluck('expo_push_token')->filter()->unique()->values()->all();
        if (! empty($tokens)) {
            $expo->sendMany($tokens, $this->payload['title'], $this->payload['body'], $this->payload['data'] ?? []);
        }

        foreach ($users as $user) {
            $webPush->sendToUser($user, [
                'title' => $this->payload['title'],
                'body' => $this->payload['body'],
                ...($this->payload['data'] ?? []),
            ]);
        }
    }
}
