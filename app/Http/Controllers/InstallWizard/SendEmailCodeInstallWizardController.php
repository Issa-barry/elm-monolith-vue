<?php

namespace App\Http\Controllers\InstallWizard;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Concerns\HasOtpRateLimitResponse;
use App\Http\Controllers\Controller;
use App\Mail\InstallEmailVerificationMail;
use App\Services\InstallationService;
use App\Services\OtpService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SendEmailCodeInstallWizardController extends Controller
{
    use HasOtpRateLimitResponse;

    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    /**
     * Envoie un code à l'email saisi pour l'étape Super Admin — appelé uniquement quand
     * l'utilisateur renseigne un email (facultatif). Le code n'est lié à aucun User (qui
     * n'existe pas encore) : il vit uniquement dans le cache OTP, scopé par email
     * (cf. InstallationService::EMAIL_OTP_CONTEXT), exactement comme
     * Auth\AcceptInvitation\CheckPhoneAcceptInvitationController.
     */
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->guard->assertSaasTokenConfigured();
        $this->guard->ensureTokenVerified($request);

        $request->validate(['email' => 'required|email:rfc,dns|max:255']);
        $email = $request->string('email')->toString();

        $wait = $otp->resendWaitSeconds($email, InstallationService::EMAIL_OTP_CONTEXT);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        $code = $otp->generate($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT);
        Mail::to($email)->send(new InstallEmailVerificationMail($code));

        return response()->json([
            'sent' => true,
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }
}
