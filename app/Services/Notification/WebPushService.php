<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Models\WebPushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Envoi Web Push (PWA Nuxt) — lib `minishlink/web-push` (protocole VAPID,
 * chiffrement `Subscription` standard W3C géré par la lib, jamais réimplémenté
 * ici). Un seul point d'entrée : NotificationDispatcher (via
 * DispatchPushNotificationsJob) — aucune classe métier ne doit appeler ce
 * service directement.
 *
 * Client PSR-18 passé explicitement (Guzzle, déjà dépendance Laravel) plutôt
 * que de compter sur `php-http/discovery` (dépendance de la lib) : Guzzle 7
 * implémente nativement `Psr\Http\Client\ClientInterface`, aucun adaptateur
 * supplémentaire nécessaire.
 */
class WebPushService
{
    public function __construct(
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.web_push.vapid_public_key'))
            && filled(config('services.web_push.vapid_private_key'));
    }

    /**
     * Envoie le même payload à tous les abonnements Web Push de l'utilisateur
     * (un par navigateur/appareil, cf. WebPushSubscription). N'échoue jamais
     * vers l'appelant : chaque échec est géré individuellement (abonnement
     * expiré supprimé, échec temporaire conservé et logué).
     */
    public function sendToUser(User $user, array $payload): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = $user->webPushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => config('services.web_push.vapid_subject'),
                'publicKey' => config('services.web_push.vapid_public_key'),
                'privateKey' => config('services.web_push.vapid_private_key'),
            ]],
            [],
            $this->httpClient ?? new Client,
        );

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->p256dh,
                        'auth' => $subscription->auth,
                    ],
                    'contentEncoding' => $subscription->content_encoding ?? 'aes128gcm',
                ]),
                json_encode($payload),
            );
        }

        foreach ($webPush->flush() as $report) {
            $this->handleReport($report);
        }
    }

    private function handleReport(MessageSentReport $report): void
    {
        $subscription = WebPushSubscription::where(
            'endpoint_hash',
            WebPushSubscription::hashEndpoint($report->getEndpoint()),
        )->first();

        if ($report->isSuccess()) {
            $subscription?->update(['last_used_at' => now()]);

            return;
        }

        // Signal natif de la lib (couvre 404/410 selon le fournisseur push) — jamais
        // réinterprété nous-mêmes depuis le code HTTP brut.
        if ($report->isSubscriptionExpired()) {
            Log::info('WebPush : abonnement expiré, supprimé', [
                'subscription_id' => $subscription?->id,
            ]);
            $subscription?->delete();

            return;
        }

        // Échec temporaire (réseau, 5xx, fournisseur indisponible...) : l'abonnement est
        // conservé, jamais supprimé sur la seule foi d'un incident ponctuel.
        Log::warning('WebPush : envoi échoué (temporaire)', [
            'subscription_id' => $subscription?->id,
            'reason' => $report->getReason(),
        ]);
    }
}
