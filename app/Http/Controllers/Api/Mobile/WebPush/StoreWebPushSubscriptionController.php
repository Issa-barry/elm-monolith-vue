<?php

namespace App\Http\Controllers\Api\Mobile\WebPush;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\WebPushSubscriptionStoreRequest;
use App\Models\WebPushSubscription;
use Illuminate\Http\JsonResponse;

/**
 * Abonnements Web Push (PWA Nuxt) — le serveur associe TOUJOURS l'abonnement au compte
 * authentifié (`auth:sanctum`, cf. routes/api.php) : le navigateur n'envoie et ne peut jamais
 * choisir un `user_id`/`organization_id`.
 */
class StoreWebPushSubscriptionController extends Controller
{
    /**
     * Idempotent : upsert par `endpoint_hash` (unique globalement — cf.
     * migration). Un même endpoint réabonné par un autre compte (poste
     * partagé) lui est réassigné, jamais dupliqué.
     */
    public function __invoke(WebPushSubscriptionStoreRequest $request): JsonResponse
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
}
