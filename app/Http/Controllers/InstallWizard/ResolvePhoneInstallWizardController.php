<?php

namespace App\Http\Controllers\InstallWizard;

use App\Http\Controllers\Controller;
use App\Services\InstallationService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResolvePhoneInstallWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    /**
     * Aperçu pays/indicatif/devise/fuseau pendant la saisie (étape Super Admin du wizard) — pas
     * de choix manuel de pays côté formulaire, cf. PhoneCountryInfo.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->guard->assertSaasTokenConfigured();
        $this->guard->ensureTokenVerified($request);

        $request->validate(['telephone' => 'required|string']);

        return response()->json([
            'info' => $this->service->resolveTelephone($request->string('telephone')->toString()),
        ]);
    }
}
