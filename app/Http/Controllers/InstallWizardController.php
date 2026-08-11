<?php

namespace App\Http\Controllers;

use App\Services\InstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistant d'installation web (/install) — parcours principal pour une première installation,
 * en complément de `php artisan app:install` (utile pour le déploiement scripté/CI). Les deux
 * délèguent exactement la même logique métier à InstallationService, jamais dupliquée ici.
 *
 * Protection à trois niveaux, dans cet ordre : (1) route bloquée dès que l'installation est
 * marquée terminée (InstallationService::isInstalled(), 404 — jamais 403, pour ne pas même
 * révéler que la route a un sens une fois utilisée) ; (2) clé APP_INSTALL_TOKEN (cf.
 * config/app.php) si configurée, vérifiée une fois puis mémorisée en session (jamais en base,
 * jamais renvoyée au client, jamais dans l'URL) ; (3) rate limiting (cf. throttle:install,
 * FortifyServiceProvider::configureRateLimiting()).
 */
class InstallWizardController extends Controller
{
    public function __construct(private readonly InstallationService $service) {}

    public function show(Request $request): Response
    {
        abort_if($this->service->isInstalled(), 404);

        if ($this->tokenRequired() && ! $request->session()->get('install_token_verified')) {
            return Inertia::render('Install/Token');
        }

        return Inertia::render('Install/Wizard');
    }

    public function verifyToken(Request $request): RedirectResponse
    {
        abort_if($this->service->isInstalled(), 404);

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

    /**
     * Aperçu pays/indicatif/devise/fuseau pendant la saisie (étape Super Admin du wizard) — pas
     * de choix manuel de pays côté formulaire, cf. PhoneCountryInfo.
     */
    public function resolvePhone(Request $request): JsonResponse
    {
        abort_if($this->service->isInstalled(), 404);
        $this->ensureTokenVerified($request);

        $request->validate(['telephone' => 'required|string']);

        return response()->json([
            'info' => $this->service->resolveTelephone($request->string('telephone')->toString()),
        ]);
    }

    public function store(Request $request): Response
    {
        abort_if($this->service->isInstalled(), 404);
        $this->ensureTokenVerified($request);

        $data = $request->validate([
            'organisation.nom' => 'required|string|max:255',
            'admin.prenom' => 'required|string|max:100',
            'admin.nom' => 'required|string|max:100',
            'admin.telephone' => 'required|string',
            'admin.email' => 'nullable|email:rfc,dns|max:255',
            'admin.password' => 'required|string',
            'admin.password_confirmation' => 'required|string',
            'catalogue.categories' => 'boolean',
            'catalogue.options' => 'boolean',
        ]);

        // La complexité/confirmation du mot de passe est revalidée par InstallationService
        // (seule source de vérité, partagée avec le CLI) — pas de règle `confirmed` ici.
        $this->service->install(
            organisation: $data['organisation'],
            admin: $data['admin'],
            catalogue: $data['catalogue'] ?? [],
        );

        $request->session()->forget('install_token_verified');

        // Rendu direct (pas de redirect) : la page Success.vue affiche la confirmation puis un
        // bouton "Se connecter" — l'installation venant tout juste de se terminer, un GET
        // immédiat sur /install renverrait de toute façon 404 (isInstalled() est maintenant vrai).
        return Inertia::render('Install/Success');
    }

    private function tokenRequired(): bool
    {
        return (bool) config('app.install_token');
    }

    private function ensureTokenVerified(Request $request): void
    {
        abort_if(
            $this->tokenRequired() && ! $request->session()->get('install_token_verified'),
            403,
            "Clé d'installation requise."
        );
    }
}
