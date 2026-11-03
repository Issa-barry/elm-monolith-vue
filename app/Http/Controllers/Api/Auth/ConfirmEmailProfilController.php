<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Client\ChangementEmailProfilService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Lien reçu sur la nouvelle adresse (ADR 0051) : ouvert sans session, souvent depuis
 * un autre appareil. La signature porte l'autorisation et prouve la possession de la
 * boîte. Signature relative : le front appelle l'API par son BFF, le domaine de la
 * demande n'est pas toujours celui du clic.
 */
class ConfirmEmailProfilController extends Controller
{
    public function __invoke(Request $request, string $userId, ChangementEmailProfilService $service): Response
    {
        $erreur = fn (bool $expire, int $statut) => response()->view('emails.verify-error', [
            'expired' => $expire,
            'conseilExpire' => 'Il a expiré après 24 heures. Modifiez à nouveau votre adresse depuis votre profil.',
            'conseilInvalide' => 'Votre adresse actuelle n\'a pas changé. Vous pouvez refaire la demande depuis votre profil.',
        ], $statut);

        if (! URL::hasCorrectSignature($request, false)) {
            return $erreur(false, 404);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $erreur(true, 410);
        }

        $user = User::find($userId);
        if (! $user || ! hash_equals($service->empreinte($user), (string) $request->query('empreinte'))) {
            return $erreur(false, 404);
        }

        try {
            $service->confirmer($user, (string) $request->query('email'));
        } catch (ValidationException) {
            return $erreur(false, 409);
        }

        return response()->view('emails.email-profil-confirme');
    }
}
