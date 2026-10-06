<?php

namespace App\Support\Auth;

use App\Services\InstallationService;
use Illuminate\Http\Request;

/**
 * Contrôles d'accès partagés par toutes les actions de l'assistant d'installation web
 * (`InstallWizard\*`) — extrait de l'ancien `InstallWizardController`, seule source des 3 niveaux
 * de protection documentés sur ce contrôleur (verrou d'installation, clé APP_INSTALL_TOKEN, session).
 */
final class InstallWizardGuard
{
    public function __construct(private readonly InstallationService $service) {}

    /**
     * En saas, `InstallationService::isLocked()` ne protège jamais /install — le token devient
     * donc la seule barrière, et doit être configuré. On échoue tôt et bruyamment (500, erreur de
     * config serveur) plutôt que de laisser /install ouvert sans protection ou boucler sur l'écran
     * Token sans jamais pouvoir le franchir.
     */
    public function assertSaasTokenConfigured(): void
    {
        abort_if(
            $this->service->isSaas() && ! config('app.install_token'),
            500,
            "Assistant d'installation SaaS mal configuré — APP_INSTALL_TOKEN est obligatoire en mode saas."
        );
    }

    public function tokenRequired(): bool
    {
        return $this->service->isSaas() || (bool) config('app.install_token');
    }

    public function ensureTokenVerified(Request $request): void
    {
        abort_if(
            $this->tokenRequired() && ! $request->session()->get('install_token_verified'),
            403,
            "Clé d'installation requise."
        );
    }
}
