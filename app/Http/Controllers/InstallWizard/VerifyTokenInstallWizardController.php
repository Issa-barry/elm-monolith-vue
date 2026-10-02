<?php

namespace App\Http\Controllers\InstallWizard;

use App\Http\Controllers\Controller;
use App\Services\InstallationService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerifyTokenInstallWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->guard->assertSaasTokenConfigured();

        $configured = config('app.install_token');
        abort_if(! $configured, 403, "Assistant d'installation non configuré — définissez APP_INSTALL_TOKEN.");

        $request->validate(['token' => 'required|string']);

        if (! hash_equals((string) $configured, (string) $request->input('token'))) {
            throw ValidationException::withMessages([
                'token' => "Clé d'installation invalide.",
            ]);
        }

        // Seul un booléen est retenu en session — jamais la clé elle-même.
        $request->session()->put('install_token_verified', true);

        return redirect()->route('install.show');
    }
}
