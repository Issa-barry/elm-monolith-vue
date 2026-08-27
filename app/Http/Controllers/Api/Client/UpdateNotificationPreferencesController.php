<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\UpdateNotificationPreferencesRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
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
    #[Endpoint(
        description: '`notification_preferences.activite` est une **préférence métier persistée '
            .'en base** (`users.notification_preferences`), pas la permission native de '
            .'notification du navigateur/téléphone (`Notification.requestPermission()`, gérée '
            .'exclusivement côté frontend) ni le jeton technique `expo_push_token` (route '
            .'`push-token`, sert à *router* le push, pas à décider si l\'utilisateur en veut). '
            .'Une catégorie jamais réglée explicitement reste activée par défaut. Toute clé hors '
            .'de la liste blanche fermée (`activite` est la seule à ce jour) est silencieusement '
            .'ignorée — pas d\'erreur, pas d\'ajout dynamique de catégorie.',
    )]
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
