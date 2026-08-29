<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\WebPushSubscriptionDestroyRequest;
use App\Http\Requests\Api\Mobile\WebPushSubscriptionStoreRequest;
use App\Models\WebPushSubscription;
use Illuminate\Http\JsonResponse;

/**
 * Abonnements Web Push (PWA Nuxt) — le serveur associe TOUJOURS l'abonnement
 * au compte authentifié (`auth:sanctum`, cf. routes/api.php) : le navigateur
 * n'envoie et ne peut jamais choisir un `user_id`/`organization_id`.
 */
class WebPushSubscriptionsController extends Controller
{
    /**
     * Clé PUBLIQUE VAPID uniquement — la clé privée ne quitte jamais le
     * serveur. `null` si l'installation n'a pas encore généré de clés
     * (canal Web Push simplement indisponible, jamais une erreur).
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => $this->resolvePublicKey(),
        ]);
    }

    private function resolvePublicKey(): ?string
    {
        return config('services.web_push.vapid_public_key');
    }

    /**
     * Idempotent : upsert par `endpoint_hash` (unique globalement — cf.
     * migration). Un même endpoint réabonné par un autre compte (poste
     * partagé) lui est réassigné, jamais dupliqué.
     */
    public function store(WebPushSubscriptionStoreRequest $request): JsonResponse
    {
        $endpoint = $request->string('endpoint')->value();

        WebPushSubscription::updateOrCreate(
            ['endpoint_hash' => WebPushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'p256dh' => $request->input('keys.p256dh'),
                'auth' => $request->input('keys.auth'),
                'content_encoding' => $request->input('content_encoding'),
                'user_agent' => $request->userAgent(),
            ],
        );

        return response()->json(['success' => true]);
    }

    /**
     * Ne supprime QUE l'abonnement identifié par `endpoint`, scopé au compte
     * authentifié — jamais un "delete all" (un User peut avoir plusieurs
     * appareils). Idempotent : un endpoint déjà absent (ou appartenant à un
     * autre compte) renvoie le même succès, sans jamais confirmer/infirmer
     * son existence pour un tiers.
     */
    public function destroy(WebPushSubscriptionDestroyRequest $request): JsonResponse
    {
        $request->user()
            ->webPushSubscriptions()
            ->where('endpoint_hash', WebPushSubscription::hashEndpoint($request->string('endpoint')->value()))
            ->delete();

        return response()->json(['success' => true]);
    }
}
