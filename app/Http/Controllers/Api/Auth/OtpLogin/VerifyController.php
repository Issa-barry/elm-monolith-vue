<?php

namespace App\Http\Controllers\Api\Auth\OtpLogin;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Api\Auth\Concerns\IssuesTelephoneLoginToken;
use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Étape 2 de la connexion sans mot de passe par OTP — vérifie le code et émet
 * un token Sanctum, exactement comme LoginController (mot de passe), via le
 * même trait `IssuesTelephoneLoginToken` : le parcours post-authentification
 * ne dépend jamais de la façon dont l'identité a été prouvée.
 *
 * RÈGLE CENTRALE (cf. rapport du 27/08/2026) : une connexion réussie ici
 * authentifie la session, mais NE MARQUE JAMAIS `UserAuthIdentity::verified_at`
 * pour l'identité téléphone — le code a pu être livré par email (canal
 * temporaire, cf. RequestController), ce qui ne prouve jamais la possession
 * du numéro. Le jour où le canal réellement utilisé prouve le téléphone
 * (SMS/WhatsApp), c'est un choix métier séparé et explicite d'appeler ou non
 * `UserAuthIdentity::markVerifiedVia()` à ce moment précis — ce contrôleur ne
 * le fait jamais lui-même, pour ne pas prendre cette décision à la place du
 * futur parcours qui l'exigera (cf. brief, point 8 : décision volontairement
 * différée).
 */
class VerifyController extends Controller
{
    use IssuesTelephoneLoginToken;

    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        if ($otp->tooManyAttempts($phone, OtpPurpose::LOGIN)) {
            return response()->json(['error' => 'Trop de tentatives. Demandez un nouveau code.'], 429);
        }

        if (! $otp->verify($phone, $request->input('code', ''), OtpPurpose::LOGIN)) {
            return response()->json(['error' => 'Code incorrect ou expiré.'], 422);
        }

        $user = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone));

        if (! $user) {
            return response()->json(['error' => 'Code incorrect ou expiré.'], 422);
        }

        $status = AccountEligibility::status($user);

        if ($status !== AccountStatus::Ok) {
            return response()->json([
                'message' => AccountEligibility::message($status),
                'code' => $status->value,
            ], 403);
        }

        $this->lierCompteParTelephone($user);

        $otp->clear($phone, OtpPurpose::LOGIN);

        $token = $user->createToken($request->input('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userResource($user),
        ]);
    }
}
