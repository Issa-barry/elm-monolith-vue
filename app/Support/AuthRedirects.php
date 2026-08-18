<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

class AuthRedirects
{
    private const CLIENT_ROLES = ['client', 'proprietaire', 'livreur'];

    private const STAFF_ROLES = ['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'];

    /**
     * Point de calcul unique de "où doit atterrir cet utilisateur" — utilisé après connexion
     * (LoginResponse/RegisterResponse via resolvePostAuthRedirect ci-dessous), après changement
     * de mot de passe forcé (ForcePasswordChangeController), sur `/` (routes/web.php), ET par le
     * middleware EnsureOrganizationHasSite (alias org.site.required) qui protège aussi les accès
     * ultérieurs au back-office (session reprise, lien profond) — cf. needsOnboarding() ci-dessous,
     * seule source de vérité pour "cette organisation a-t-elle besoin de l'onboarding ?".
     */
    public static function defaultPathForUser(?User $user): string
    {
        if ($user?->hasAnyRole(self::CLIENT_ROLES)) {
            return route('client.dashboard');
        }

        if (! $user?->hasAnyRole(self::STAFF_ROLES)) {
            return route('home');
        }

        return route(self::needsOnboarding($user) ? 'onboarding.site.show' : 'dashboard');
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
        // Ne s'applique qu'au staff : les routes /client/* ne sont jamais gardées par
        // EnsureOrganizationHasSite (cf. routes/web.php), donc un client n'a rien à onboarder.
        if (! $user?->hasAnyRole(self::CLIENT_ROLES) && self::needsOnboarding($user)) {
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

    private static function isIntendedAllowedForUser(string $intendedUrl, ?User $user): bool
    {
        $path = parse_url($intendedUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        $normalizedPath = '/'.ltrim($path, '/');

        if ($user?->hasAnyRole(self::CLIENT_ROLES)) {
            return str_starts_with($normalizedPath, '/client');
        }

        if ($user?->hasAnyRole(self::STAFF_ROLES)) {
            return ! str_starts_with($normalizedPath, '/client');
        }

        // /contact et /help n'existent plus côté back-office (portées par
        // elm-vitrine désormais) — seule '/' reste une destination valide ici.
        return $normalizedPath === '/';
    }

    public static function hasKnownRole(?User $user): bool
    {
        return (bool) $user?->hasAnyRole([...self::CLIENT_ROLES, ...self::STAFF_ROLES]);
    }
}
