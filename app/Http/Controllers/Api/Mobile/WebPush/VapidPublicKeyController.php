<?php

namespace App\Http\Controllers\Api\Mobile\WebPush;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VapidPublicKeyController extends Controller
{
    /**
     * Clé PUBLIQUE VAPID uniquement — la clé privée ne quitte jamais le
     * serveur. `null` si l'installation n'a pas encore généré de clés
     * (canal Web Push simplement indisponible, jamais une erreur).
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'public_key' => $this->resolvePublicKey(),
        ]);
    }

    // Type de retour explicite (?string) nécessaire : Scramble infère le schéma OpenAPI depuis
    // la signature de cette méthode, pas depuis config() (mixed) — sans elle, le contrat
    // documenté perd la nullabilité de public_key (constaté à l'export après extraction).
    private function resolvePublicKey(): ?string
    {
        return config('services.web_push.vapid_public_key');
    }
}
