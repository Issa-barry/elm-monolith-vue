<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterLookupController extends Controller
{
    /**
     * Recherche un numéro de téléphone dans toutes les tables métier et génère un OTP.
     *
     * Retourne :
     *  - user_exists        → numéro déjà associé à un compte Users
     *  - prefill_available  → nom/prénom trouvés dans clients/livreurs/proprietaires
     *  - not_found          → aucune correspondance (inscription classique)
     *
     * Dans les deux derniers cas, un OTP est généré et stocké en session.
     */
    public function __invoke(Request $request, OtpService $otp, UserInvitationService $invitations): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json([
                'error' => 'Numéro de téléphone invalide.',
            ], 422);
        }

        // 1. Téléphone déjà dans users → orienter vers connexion
        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone)) !== null) {
            return response()->json(['status' => 'user_exists']);
        }

        // 2. Rechercher un préremplissage dans les tables métier
        $prefill = $invitations->phonePrefill($phone);

        if (! $otp->canSend($phone)) {
            return response()->json(['error' => 'Trop de demandes de code. Veuillez réessayer plus tard.'], 429);
        }

        // 3. Générer l'OTP (stocké en cache, pas encore envoyé par SMS/WhatsApp en
        // MVP — aucun fournisseur téléphonique n'est encore configuré dans
        // config('otp.channels'), cf. rapport du 27/08/2026). Reste un flux
        // fonctionnel uniquement avec OTP_FIXED_CODE (local/tests) en attendant.
        $otp->generate($phone, OtpPurpose::PHONE_VERIFICATION);

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
        ]);
    }
}
