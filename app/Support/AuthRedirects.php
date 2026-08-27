<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

class AuthRedirects
{
    /**
     * Point de calcul unique de "où doit atterrir cet utilisateur" — utilisé après connexion
     * (LoginResponse/RegisterResponse via resolvePostAuthRedirect ci-dessous), après changement
     * de mot de passe forcé (ForcePasswordChangeController), sur `/` (routes/web.php), ET par le
     * middleware EnsureOrganizationHasSite (alias org.site.required) qui protège aussi les accès
     * ultérieurs au back-office (session reprise, lien profond) — cf. needsOnboarding() ci-dessous,
     * seule source de vérité pour "cette organisation a-t-elle besoin de l'onboarding ?".
     *
     * Un compte qui cumule un rôle staff ET un rôle client/proprietaire/livreur
     * atterrit sur le backoffice par défaut (ce point d'entrée n'est utilisé que
     * par le flux de connexion web/backoffice — le login API/Nuxt ne redirige
     * jamais ici) ; il garde un lien explicite vers l'espace client dans
     * l'interface plutôt qu'une redirection automatique — cf. User::
     * hasBackofficeAccess()/hasClientAccess(), UserMenuContent.vue.
     */
    public static function defaultPathForUser(?User $user): string
    {
        if ($user?->hasBackofficeAccess()) {
            return route(self::needsOnboarding($user) ? 'onboarding.site.show' : 'dashboard');
        }

        if ($user?->hasClientAccess()) {
            return route('client.dashboard');
        }

        return route('home');
    }

    /**
     * Source de vérité unique pour "cette organisation doit-elle passer par l'onboarding du
     * premier site avant tout accès normal au back-office ?" — une organisation sans aucun site
     * (le premier site n'est plus créé pendant /install, cf. InstallationService) répond oui,
     * quel que soit le rôle du membre, super_admin compris (lui seul peut d'ailleurs créer ce
     * premier site, cf. OnboardingSiteController). Réutilisée à la fois ici (redirection post-
     * connexion) et par le middleware EnsureOrganizationHasSite (protection des accès ultérieurs)
     * pour ne jamais diverger — ne dupliquez pas cette requête ailleurs.
     */
    public static function needsOnboarding(?User $user): bool
    {
        return (bool) $user?->organization_id
            && Site::where('organization_id', $user->organization_id)->doesntExist();
    }

    public static function resolvePostAuthRedirect(Request $request, ?User $user): string
    {
        $default = self::defaultPathForUser($user);

        // L'onboarding est une règle métier qui prime sur toute intended URL mémorisée en
        // session par le middleware 'auth' (ex : un lien profond visité avant la connexion,
        // voire avant même /install) — sinon une intended URL par ailleurs "autorisée" pour le
        // rôle de l'utilisateur pouvait faire atterrir une organisation fraîche directement sur
        // le back-office sans site. Cf. rapport de bug "Aucun site affecté" (comportement
        // intermittent selon qu'une intended URL était ou non présente en session).
        // Ne s'applique qu'à un compte qui va effectivement sur le backoffice : les
        // routes /client/* ne sont jamais gardées par EnsureOrganizationHasSite (cf.
        // routes/web.php), donc un compte purement client n'a rien à onboarder. Un
        // compte qui cumule staff+client passe bien par cette priorité (il va sur le
        // backoffice par défaut, cf. defaultPathForUser ci-dessus).
        if ($user?->hasBackofficeAccess() && self::needsOnboarding($user)) {
            $request->session()->forget('url.intended');

            return $default;
        }

        $intendedUrl = (string) $request->session()->get('url.intended', '');

        if ($intendedUrl === '' || ! self::isIntendedAllowedForUser($intendedUrl, $user)) {
            $request->session()->forget('url.intended');

            return $default;
        }

        return $intendedUrl;
    }

    /**
     * Un compte qui cumule staff+client peut légitimement être renvoyé vers l'une
     * OU l'autre zone (deep link) — les deux accès ne s'excluent jamais, cf.
     * User::hasBackofficeAccess()/hasClientAccess().
     */
    private static function isIntendedAllowedForUser(string $intendedUrl, ?User $user): bool
    {
        $path = parse_url($intendedUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        $normalizedPath = '/'.ltrim($path, '/');

        if (str_starts_with($normalizedPath, '/client')) {
            return (bool) $user?->hasClientAccess();
        }

        if ($user?->hasBackofficeAccess()) {
            return true;
        }

        // /contact et /help n'existent plus côté back-office (portées par
        // elm-vitrine désormais) — seule '/' reste une destination valide ici.
        return $normalizedPath === '/';
    }

    public static function hasKnownRole(?User $user): bool
    {
        return (bool) ($user?->hasBackofficeAccess() || $user?->hasClientAccess());
    }
}
