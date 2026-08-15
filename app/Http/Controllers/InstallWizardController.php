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
 * Protection, dans cet ordre : (1) verrou InstallationService::isLocked() — en on_premise,
 * ferme /install dès la première installation (redirige vers /login sur show(), 404 sur les
 * autres actions ; jamais 403, pour ne pas révéler que la route a un sens une fois utilisée) ;
 * en saas, jamais verrouillé, /install reste accessible pour créer d'autres organisations ;
 * (2) clé APP_INSTALL_TOKEN (cf. config/app.php) — optionnelle en on_premise, TOUJOURS
 * obligatoire en saas puisque le verrou (1) n'y protège plus rien ; vérifiée une fois puis
 * mémorisée en session (jamais en base, jamais renvoyée au client, jamais dans l'URL) ;
 * (3) rate limiting (cf. throttle:install, FortifyServiceProvider::configureRateLimiting()).
 */
class InstallWizardController extends Controller
{
    public function __construct(private readonly InstallationService $service) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->service->isLocked()) {
            return redirect()->route('login');
        }

        $this->assertSaasTokenConfigured();

        if ($this->tokenRequired() && ! $request->session()->get('install_token_verified')) {
            return Inertia::render('Install/Token');
        }

        return Inertia::render('Install/Wizard');
    }

    public function verifyToken(Request $request): RedirectResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();

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
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();
        $this->ensureTokenVerified($request);

        $request->validate(['telephone' => 'required|string']);

        return response()->json([
            'info' => $this->service->resolveTelephone($request->string('telephone')->toString()),
        ]);
    }

    public function store(Request $request): Response
    {
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();
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
            'catalogue.types_vehicule' => 'boolean',
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
        // bouton "Se connecter" — en on_premise, l'installation venant de se terminer, un GET
        // immédiat sur /install redirigerait de toute façon vers /login (isLocked() est
        // maintenant vrai) ; en saas, /install resterait au contraire accessible pour une
        // organisation suivante, ce qui ne change rien à l'intérêt de rendre Success ici.
        return Inertia::render('Install/Success');
    }

    /**
     * En saas, isLocked() ne protège jamais /install (cf. docblock de classe) — le token devient
     * donc la seule barrière, et doit être configuré. On échoue tôt et bruyamment (500, erreur de
     * config serveur) plutôt que de laisser /install ouvert sans protection ou boucler sur l'écran
     * Token sans jamais pouvoir le franchir.
     */
    private function assertSaasTokenConfigured(): void
    {
        abort_if(
            $this->service->isSaas() && ! config('app.install_token'),
            500,
            "Assistant d'installation SaaS mal configuré — APP_INSTALL_TOKEN est obligatoire en mode saas."
        );
    }

    private function tokenRequired(): bool
    {
        return $this->service->isSaas() || (bool) config('app.install_token');
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
