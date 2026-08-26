<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\UpdateNotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Persiste la préférence de notification métier de l'utilisateur (distincte du
 * jeton push `expo_push_token`, purement technique) — source de vérité commune
 * à Nuxt, PWA, mobile : jamais un simple état local/localStorage, qui se
 * perdrait ou diverger selon l'appareil. Toute clé absente de
 * User::NOTIFICATION_PREFERENCE_DEFAULTS est silencieusement ignorée (liste
 * blanche fermée, cf. docblock de la constante).
 */
class UpdateNotificationPreferencesController extends Controller
{
    public function __invoke(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $allowed = array_intersect_key(
            $request->validated('preferences'),
            User::NOTIFICATION_PREFERENCE_DEFAULTS,
        );

        $user->update([
            'notification_preferences' => array_merge($user->notification_preferences ?? [], $allowed),
        ]);

        return response()->json(['notifications' => $user->fresh()->notificationPreferences()]);
    }
}
