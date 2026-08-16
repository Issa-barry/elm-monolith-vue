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
     * Point de calcul unique de "où doit atterrir cet utilisateur" — utilisé aussi bien après
     * connexion (LoginResponse/RegisterResponse via resolvePostAuthRedirect ci-dessous) qu'après
     * changement de mot de passe forcé (ForcePasswordChangeController) et sur `/` (routes/web.php).
     * C'est ICI, plutôt qu'un middleware sur la route dashboard, que vit la redirection vers
     * l'onboarding du premier site (cf. OnboardingSiteController) : le premier site n'est plus
     * créé pendant /install (cf. InstallationService), donc une organisation fraîche n'en a
     * encore aucun. Un middleware sur /backoffice/dashboard aurait aussi intercepté n'importe
     * quel accès direct à cette route (tests, liens profonds) avant même la vérification des
     * permissions propres à chaque contrôleur — jugé trop large, cf. rapport d'installation.
     */
    public static function defaultPathForUser(?User $user): string
    {
        if ($user?->hasAnyRole(self::CLIENT_ROLES)) {
            return route('client.dashboard');
        }

        if (! $user?->hasAnyRole(self::STAFF_ROLES)) {
            return route('home');
        }

        $needsOnboarding = $user->organization_id
            && Site::where('organization_id', $user->organization_id)->doesntExist();

        return route($needsOnboarding ? 'onboarding.site.show' : 'dashboard');
    }

    public static function resolvePostAuthRedirect(Request $request, ?User $user): string
    {
        $default = self::defaultPathForUser($user);
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
