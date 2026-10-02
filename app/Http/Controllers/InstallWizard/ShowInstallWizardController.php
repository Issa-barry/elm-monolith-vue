<?php

namespace App\Http\Controllers\InstallWizard;

use App\Enums\DomaineActivite;
use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Services\InstallationService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistant d'installation web (/install) — parcours principal pour une première installation,
 * en complément de `php artisan app:install` (utile pour le déploiement scripté/CI). Les deux
 * délèguent exactement la même logique métier à InstallationService, jamais dupliquée ici.
 *
 * Protection, dans cet ordre : (1) verrou InstallationService::isLocked() — en on_premise,
 * ferme /install dès la première installation (redirige vers /login sur show(), 404 sur les
 * autres actions ; jamais 403, pour ne pas révéler que la route a un sens une fois utilisée) ;
 * en saas, jamais verrouillé, /install reste accessible pour créer d'autres organisations ;
 * (2) clé APP_INSTALL_TOKEN (cf. config/app.php) — optionnelle en on_premise, TOUJOURS
 * obligatoire en saas puisque le verrou (1) n'y protège plus rien ; vérifiée une fois puis
 * mémorisée en session (jamais en base, jamais renvoyée au client, jamais dans l'URL) ;
 * (3) rate limiting (cf. throttle:install, FortifyServiceProvider::configureRateLimiting()).
 */
class ShowInstallWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($this->service->isLocked()) {
            return redirect()->route('login');
        }

        $this->guard->assertSaasTokenConfigured();

        if ($this->guard->tokenRequired() && ! $request->session()->get('install_token_verified')) {
            return Inertia::render('Install/Token');
        }

        return Inertia::render('Install/Wizard', [
            // 'site_types' de chaque domaine (cf. DomaineActivite::options()) alimente les
            // suggestions de l'étape "Site principal" ; types_tous permet d'afficher la liste
            // complète si l'utilisateur ne s'y retrouve pas dans les suggestions.
            'domaines' => DomaineActivite::options(),
            'types_tous' => SiteType::options(),
            // Dérivé de InstallationService::isSaas() (config('app.deployment_mode')) — jamais une
            // deuxième interprétation du mode côté frontend : le backend reste la seule source de
            // vérité, ce booléen n'est qu'un affichage/UX (email obligatoire ou non), la validation
            // réelle est de toute façon revalidée par InstallationService::install().
            'isSaas' => $this->service->isSaas(),
        ]);
    }
}
