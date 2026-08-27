<?php

namespace App\Http\Controllers\Api\Auth\OtpLogin;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Concerns\HasOtpRateLimitResponse;
use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\Otp\OtpChannelResolver;
use App\Services\Otp\OtpDestinationMasker;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Étape 1 de la connexion sans mot de passe par OTP (cf. rapport du
 * 27/08/2026) : envoie un code de connexion au compte existant lié à ce
 * numéro. Contrairement à `LoginController` (mot de passe), c'est un point
 * d'entrée GÉNÉRIQUE quant au canal — aujourd'hui email (seul canal câblé),
 * demain WhatsApp/SMS dès qu'un fournisseur sera configuré dans
 * `config('otp.channels')`, sans aucun changement ici.
 *
 * `purpose=login` : ce code authentifie une session, il ne vérifie JAMAIS
 * le téléphone (cf. VerifyController — `UserAuthIdentity::verified_at` n'est
 * jamais touché par ce parcours).
 */
class RequestController extends Controller
{
    use HasOtpRateLimitResponse;

    public function __invoke(Request $request, OtpService $otp, OtpChannelResolver $channels, OtpDestinationMasker $masker): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        $user = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone));

        if (! $user) {
            return response()->json(['error' => 'Aucun compte trouvé pour ce numéro de téléphone.'], 404);
        }

        $wait = $otp->resendWaitSeconds($phone);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        // firstAvailableFor() vérifie déjà que le compte a une coordonnée
        // exploitable pour le canal choisi (cf. OtpChannelResolver) — le
        // second appel à destinationFor() ci-dessous ne peut donc plus
        // renvoyer null pour CE canal précis ; gardé par défense en
        // profondeur seulement, jamais censé se déclencher.
        $channel = $channels->firstAvailableFor(OtpPurpose::LOGIN, $phone, $user->email);

        if ($channel === null) {
            return response()->json(['error' => 'Aucun canal disponible pour recevoir un code de connexion pour le moment.'], 503);
        }

        $destination = $channels->destinationFor($channel->channel(), $phone, $user->email);

        if ($destination === null) {
            return response()->json(['error' => "Impossible d'envoyer un code de connexion à ce compte pour le moment."], 503);
        }

        $otp->generateAndSend($phone, OtpPurpose::LOGIN, $channel, $destination);

        return response()->json([
            'sent' => true,
            'channel' => $channel->channel()->value,
            // Ajouté le 27/08/2026 (demande front) : la coordonnée RÉELLEMENT
            // utilisée pour ce canal, déjà masquée côté serveur (cf.
            // OtpDestinationMasker) — jamais l'adresse/le numéro complet,
            // jamais reconstruite côté client à partir d'une autre source.
            'destination_masked' => $masker->mask($channel->channel(), $destination),
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }
}
