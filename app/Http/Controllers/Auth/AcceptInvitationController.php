<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    /**
     * GET /invitations/accept/{token}
     * Render the onboarding stepper page (or an error state).
     */
    public function show(Request $request, string $token, UserInvitationService $service): Response
    {
        $invitation = $service->findByToken($token);

        $error = $this->invitationErrorState($invitation)
            ?? $this->queryErrorState((string) $request->query('state', ''));

        if ($error !== null) {
            return Inertia::render('Invitations/Accept', ['error' => $error]);
        }

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'site_type_label' => $invitation->site->type_label,
            'site_nom' => $invitation->site->nom,
        ]);
    }

    /**
     * POST /invitations/accept/{token}/phone
     * Step 1: look up the submitted phone number.
     * Returns status + optional prefill (same contract as /register/lookup).
     */
    public function checkPhone(Request $request, string $token, OtpService $otp, UserInvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['error' => 'Invitation invalide ou expirée.'], 422);
        }

        $request->validate(['telephone' => ['required', 'string']]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        if (User::where('telephone', $phone)->exists()) {
            return response()->json(['status' => 'user_exists']);
        }

        $context = $this->otpContext($invitation);

        if (! $otp->canSend($phone, $context)) {
            return response()->json(['error' => 'Trop de demandes de code. Veuillez réessayer plus tard.'], 429);
        }

        $prefill = $service->phonePrefill($phone);
        $code = $otp->generate($phone, $context);

        Mail::to($invitation->email)->send(new OtpInvitationMail($code));

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
        ]);
    }

    /**
     * POST /invitations/accept/{token}/otp
     * Step 2: verify the OTP code.
     */
    public function verifyOtp(Request $request, string $token, OtpService $otp, UserInvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['error' => 'Invitation invalide ou expirée.'], 422);
        }

        $request->validate([
            'telephone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        $context = $this->otpContext($invitation);

        if ($otp->tooManyAttempts($phone, $context)) {
            return response()->json(['error' => 'Trop de tentatives. Demandez un nouveau code.'], 429);
        }

        if (! $otp->verify($phone, $request->input('code', ''), $context)) {
            return response()->json(['error' => 'Code incorrect ou expiré.'], 422);
        }

        $otp->markVerified($phone, $context);

        return response()->json(['verified' => true]);
    }

    /**
     * POST /invitations/accept/{token}
     * Final step: validate data, create user, log them in.
     */
    public function accept(Request $request, string $token, OtpService $otp, UserInvitationService $service): RedirectResponse|JsonResponse
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
                'state' => $this->invitationErrorState($invitation) ?? 'not_found',
            ]);
        }

        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30', 'unique:users,telephone'],
            'code_pays' => ['nullable', 'string', 'max:5'],
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $context = $this->otpContext($invitation);

        if (! $otp->isVerified($data['telephone'], $context)) {
            throw ValidationException::withMessages([
                'telephone' => 'Veuillez vérifier votre numéro de téléphone.',
            ]);
        }

        $user = $service->accept($invitation, $data);

        $otp->clear($data['telephone'], $context);

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    /**
     * Lie l'OTP à cette invitation précise (id + email) : un même numéro de téléphone
     * réinvité ne peut jamais réutiliser/hériter d'un code généré pour une autre invitation.
     */
    private function otpContext(UserInvitation $invitation): string
    {
        return $invitation->id.'|'.$invitation->email;
    }

    private function invitationErrorState(?UserInvitation $invitation): ?string
    {
        return match (true) {
            $invitation === null => 'not_found',
            $invitation->isAccepted() => 'already_accepted',
            $invitation->isRevoked() => 'revoked',
            $invitation->isExpired() => 'expired',
            default => null,
        };
    }

    private function queryErrorState(string $state): ?string
    {
        return in_array($state, [
            'already_authenticated',
            'not_found',
            'already_accepted',
            'revoked',
            'expired',
        ], true) ? $state : null;
    }
}
