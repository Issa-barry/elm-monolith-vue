<?php

namespace App\Http\Controllers\Api\Mobile\WebPush;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\WebPushSubscriptionDestroyRequest;
use App\Models\WebPushSubscription;
use Illuminate\Http\JsonResponse;

class DestroyWebPushSubscriptionController extends Controller
{
    /**
     * Ne supprime QUE l'abonnement identifié par `endpoint`, scopé au compte
     * authentifié — jamais un "delete all" (un User peut avoir plusieurs
     * appareils). Idempotent : un endpoint déjà absent (ou appartenant à un
     * autre compte) renvoie le même succès, sans jamais confirmer/infirmer
     * son existence pour un tiers.
     */
    public function __invoke(WebPushSubscriptionDestroyRequest $request): JsonResponse
    {
        $request->user()
            ->webPushSubscriptions()
            ->where('endpoint_hash', WebPushSubscription::hashEndpoint($request->string('endpoint')->value()))
            ->delete();

        return response()->json(['success' => true]);
    }
}
