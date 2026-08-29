<?php

namespace App\Http\Controllers;

use App\Enums\DomaineActivite;
use App\Enums\OtpPurpose;
use App\Enums\SiteType;
use App\Http\Controllers\Concerns\HasOtpRateLimitResponse;
use App\Mail\InstallEmailVerificationMail;
use App\Services\InstallationService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
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
    use HasOtpRateLimitResponse;

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

    /**
     * Envoie un code à l'email saisi pour l'étape Super Admin — appelé uniquement quand
     * l'utilisateur renseigne un email (facultatif). Le code n'est lié à aucun User (qui
     * n'existe pas encore) : il vit uniquement dans le cache OTP, scopé par email
     * (cf. InstallationService::EMAIL_OTP_CONTEXT), exactement comme AcceptInvitationController.
     */
    public function sendEmailCode(Request $request, OtpService $otp): JsonResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();
        $this->ensureTokenVerified($request);

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

    /**
     * Vérifie le code saisi pour l'email de l'étape Super Admin. Ne crée rien en base : marque
     * seulement le cache OTP comme vérifié pour cet email (lu par InstallationService::install()
     * au moment du submit final) — un abandon en cours de route ne laisse donc aucune trace.
     */
    public function verifyEmailCode(Request $request, OtpService $otp): JsonResponse
    {
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();
        $this->ensureTokenVerified($request);

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

    public function store(Request $request): Response
    {
        abort_if($this->service->isLocked(), 404);
        $this->assertSaasTokenConfigured();
        $this->ensureTokenVerified($request);

        $data = $request->validate([
            'organisation.nom' => 'required|string|max:255',
            'organisation.domaine' => ['required', 'string', Rule::in(array_column(DomaineActivite::cases(), 'value'))],
            'admin.prenom' => 'required|string|max:100',
            'admin.nom' => 'required|string|max:100',
            'admin.telephone' => 'required|string',
            // Obligatoire en on_premise, facultatif en saas — même règle appliquée en aval par
            // InstallationService::install() (seule source de vérité, revalidée indépendamment de
            // cette règle-ci qui ne sert qu'à renvoyer une erreur tôt, avec le bon message).
            'admin.email' => [$this->service->isSaas() ? 'nullable' : 'required', 'email:rfc,dns', 'max:255'],
            'admin.password' => 'required|string',
            // Le mot de passe est saisi une seule fois (pas de champ de confirmation dans le
            // formulaire) — `nullable` seulement pour ne pas casser un éventuel appel API qui en
            // enverrait quand même un, auquel cas InstallationService le revalide (doit alors
            // correspondre au mot de passe, cf. sa règle `confirmed`).
            'admin.password_confirmation' => 'nullable|string',
            'site.type' => ['required', 'string', Rule::in(array_column(SiteType::cases(), 'value'))],
            'site.ville' => 'required|string|max:100',
            'site.quartier' => 'required|string|max:100',
        ]);

        // La complexité/confirmation du mot de passe est revalidée par InstallationService
        // (seule source de vérité, partagée avec le CLI) — pas de règle `confirmed` ici.
        $this->service->install(
            organisation: $data['organisation'],
            admin: $data['admin'],
            site: $data['site'],
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
