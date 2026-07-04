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
        // État affiché juste après la création du compte : ce n'est pas une erreur,
        // donc vérifié avant invitationErrorState() (qui verrait sinon "already_accepted").
        if ((string) $request->query('state', '') === 'pending_validation') {
            return Inertia::render('Invitations/Accept', ['pending_validation' => true]);
        }

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

        $wait = $otp->resendWaitSeconds($phone, $context);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        $prefill = $service->phonePrefill($phone);
        $code = $otp->generate($phone, $context);

        Mail::to($invitation->email)->send(new OtpInvitationMail($code));

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }

    /**
     * POST /invitations/accept/{token}/otp/resend
     * Renvoie un nouveau code : invalide l'ancien, réinitialise les tentatives,
     * envoie un nouvel email. Toujours disponible côté UI, mais soumis aux mêmes
     * limites anti-spam (cooldown + plafonds horaire/journalier) que l'envoi initial.
     */
    public function resendOtp(Request $request, string $token, OtpService $otp, UserInvitationService $service): JsonResponse
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

        $context = $this->otpContext($invitation);

        $wait = $otp->resendWaitSeconds($phone, $context);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        $code = $otp->generate($phone, $context);

        Mail::to($invitation->email)->send(new OtpInvitationMail($code));

        return response()->json([
            'resent' => true,
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }

    /**
     * Réponse 429 uniforme pour tout envoi/renvoi de code bloqué par une limite
     * anti-spam, sans jamais préciser laquelle (cooldown, plafond horaire ou
     * journalier) — seul le délai d'attente est communiqué au client.
     */
    private function tooManyRequestsResponse(int $waitSeconds): JsonResponse
    {
        $minutes = max(1, (int) ceil($waitSeconds / 60));

        return response()->json([
            'error' => "Vous avez demandé trop de codes. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
            'retry_after_seconds' => $waitSeconds,
        ], 429);
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
            return response()->json([
                'error' => 'Trop de tentatives. Demandez un nouveau code.',
                'reason' => 'locked',
            ], 429);
        }

        if (! $otp->hasActiveCode($phone, $context)) {
            return response()->json([
                'error' => 'Votre code a expiré.',
                'reason' => 'expired',
            ], 422);
        }

        if (! $otp->verify($phone, $request->input('code', ''), $context)) {
            return response()->json([
                'error' => 'Code incorrect.',
                'reason' => 'invalid',
            ], 422);
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

        $service->accept($invitation, $data);

        $otp->clear($data['telephone'], $context);

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
