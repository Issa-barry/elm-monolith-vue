<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\ModuleService;
use App\Services\PhoneNormalizer;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LockoutResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );

        $this->app->singleton(
            RegisterResponse::class,
            \App\Http\Responses\RegisterResponse::class,
        );

        $this->app->singleton(
            LockoutResponse::class,
            \App\Http\Responses\LockoutResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureLoginByCodeRoute();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request) {
            $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

            if ($phone === null) {
                throw ValidationException::withMessages([
                    'telephone' => ['Format de téléphone invalide. Utilisez le format international (ex : +33612345678).'],
                ]);
            }

            $user = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone));

            if (! $user || ! Hash::check($request->password, $user->password)) {
                // Message en dur (comme les autres branches ci-dessous) plutôt que
                // `return null` : le fallback interne de Fortify (trans('auth.failed'))
                // dépend de la résolution de APP_LOCALE côté serveur, qui peut retomber
                // en anglais en prod (config cachée, env mal propagé...). Le login ne
                // doit jamais afficher de message en anglais.
                throw ValidationException::withMessages([
                    'telephone' => ['Numéro de téléphone ou mot de passe incorrect.'],
                ]);
            }

            // AccountEligibility priorise déjà le statut du compte (en attente/désactivé)
            // sur l'email non vérifié : même une fois l'email vérifié, l'utilisateur ne
            // pourrait toujours pas se connecter, donc c'est le message le plus pertinent.
            $status = AccountEligibility::status($user);

            if ($status !== AccountStatus::Ok) {
                throw ValidationException::withMessages(['telephone' => [AccountEligibility::message($status)]]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', self::loginProps($request)));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(static function () {
            abort_unless(self::canRegister(), 404);

            return Inertia::render('auth/Register');
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Props communes à la page de connexion — factorisées pour être
     * réutilisées par la route /login/{organisation:code} (voir
     * configureLoginByCodeRoute()), qui affiche en plus le logo/nom de
     * l'organisation identifiée par son code avant toute authentification.
     */
    private static function loginProps(Request $request, ?Organization $organisation = null): array
    {
        return [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => self::canRegister(),
            'status' => $request->session()->get('status'),
            'orgBranding' => $organisation ? [
                'name' => $organisation->name,
                'logo_url' => $organisation->logo_url,
            ] : null,
        ];
    }

    /**
     * Route de connexion dédiée par organisation (ex: /login/FDO), pour
     * partager un lien de connexion qui affiche directement le logo/nom de
     * l'organisation avant que l'utilisateur ait saisi son téléphone —
     * l'authentification elle-même reste sur POST /login (Fortify), le
     * téléphone étant unique tous comptes confondus.
     */
    private function configureLoginByCodeRoute(): void
    {
        // static fn (et non fn) : une closure non-static définie dans une méthode
        // d'instance lie implicitement $this — ici le ServiceProvider, qui porte
        // le conteneur Laravel entier ($app). route:cache tente alors de
        // sérialiser tout ce graphe (config, bindings, tous les providers...) et
        // épuise la mémoire. self::loginProps() est un appel statique résolu à la
        // compilation, qui ne capture aucun état.
        Route::middleware(['web', 'guest:'.config('fortify.guard')])
            ->get('login/{organisation:code}', static fn (Request $request, Organization $organisation) => Inertia::render(
                'auth/Login',
                self::loginProps($request, $organisation)
            ))
            ->name('login.org');
    }

    /**
     * L'inscription web est active si:
     * 1) WEB_REGISTRATION_ENABLED n'est pas explicitement false
     * 2) La feature Fortify registration est active
     * 3) Le flag Pennant module.inscription est actif pour l'organisation publique
     */
    private static function canRegister(): bool
    {
        if (! env('WEB_REGISTRATION_ENABLED', true)) {
            return false;
        }

        if (! Features::enabled(Features::registration())) {
            return false;
        }

        return ModuleService::isPublicActive(ModuleFeature::INSCRIPTION);
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('telephone', '')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Assistant d'installation web (/install) — route sensible tant qu'aucune organisation
        // n'est marquée installée, cf. InstallWizardController.
        RateLimiter::for('install', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // OTP (onboarding par invitation) : clé composite téléphone+IP, pour qu'un
        // numéro ne puisse pas être bombardé/bruteforcé depuis plusieurs IP.
        RateLimiter::for('otp-send', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('telephone', '')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('telephone', '')).'|'.$request->ip());

            return Limit::perMinute(10)->by($throttleKey);
        });

        // OTP (vérification email pendant /install) : mêmes plafonds que otp-send/otp-verify,
        // mais clé composite email+IP — l'entrée de la requête est `email`, pas `telephone`.
        RateLimiter::for('otp-email-send', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('email', '')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('otp-email-verify', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('email', '')).'|'.$request->ip());

            return Limit::perMinute(10)->by($throttleKey);
        });
    }
}
