<?php

namespace App\Http\Controllers\Auth\AcceptInvitation;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\UserInvitationService;
use App\Support\Auth\AcceptInvitationStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AcceptAcceptInvitationController extends Controller
{
    /**
     * POST /invitations/accept/{token}
     * Final step: validate data, create user, log them in.
     */
    public function __invoke(Request $request, string $token, OtpService $otp, UserInvitationService $service): RedirectResponse|JsonResponse
    {
        if (Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Vous êtes déjà connecté.'], 422);
            }

            return redirect()->route('invitations.accept', [
                'token' => $token,
                'state' => 'already_authenticated',
            ]);
        }

        $invitation = $service->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invitation invalide ou expirée.'], 422);
            }

            return redirect()->route('invitations.accept', [
                'token' => $token,
                'state' => AcceptInvitationStates::invitationErrorState($invitation) ?? 'not_found',
            ]);
        }

        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'code_pays' => ['nullable', 'string', 'max:5'],
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        // Remplace l'ancienne règle unique:users,telephone — telephone ne vit plus sur users,
        // l'unicité de connexion se vérifie désormais via user_auth_identities.
        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($data['telephone'])) !== null) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé.',
            ]);
        }

        $context = AcceptInvitationStates::otpContext($invitation);

        if (! $otp->isVerified($data['telephone'], OtpPurpose::INVITATION, $context)) {
            throw ValidationException::withMessages([
                'telephone' => 'Veuillez vérifier votre numéro de téléphone.',
            ]);
        }

        $service->accept($invitation, $data);

        $otp->clear($data['telephone'], OtpPurpose::INVITATION, $context);

        // Le compte est créé en pending_validation : jamais de connexion automatique
        // ni d'accès au dashboard tant qu'un admin ne l'a pas validé.
        if ($request->expectsJson()) {
            return response()->json([
                'pending_validation' => true,
                'message' => 'Votre compte a bien été créé. Il est en attente de validation par un administrateur.',
            ]);
        }

        return redirect()->route('invitations.accept', [
            'token' => $token,
            'state' => 'pending_validation',
        ]);
    }
}
