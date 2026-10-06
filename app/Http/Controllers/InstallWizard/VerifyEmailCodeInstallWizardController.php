<?php

namespace App\Http\Controllers\InstallWizard;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Services\InstallationService;
use App\Services\OtpService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailCodeInstallWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    /**
     * Vérifie le code saisi pour l'email de l'étape Super Admin. Ne crée rien en base : marque
     * seulement le cache OTP comme vérifié pour cet email (lu par InstallationService::install()
     * au moment du submit final) — un abandon en cours de route ne laisse donc aucune trace.
     */
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->guard->assertSaasTokenConfigured();
        $this->guard->ensureTokenVerified($request);

        $request->validate([
            'email' => 'required|email:rfc,dns|max:255',
            'code' => 'required|string|digits:6',
        ]);
        $email = $request->string('email')->toString();

        if ($otp->tooManyAttempts($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
            return response()->json(['error' => 'Trop de tentatives. Demandez un nouveau code.', 'reason' => 'locked'], 429);
        }

        if (! $otp->hasActiveCode($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
            return response()->json(['error' => 'Votre code a expiré.', 'reason' => 'expired'], 422);
        }

        if (! $otp->verify($email, $request->input('code', ''), OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
            return response()->json(['error' => 'Code incorrect.', 'reason' => 'invalid'], 422);
        }

        $otp->markVerified($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT);

        return response()->json(['verified' => true]);
    }
}
