<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class AuthRedirects
{
    private const CLIENT_ROLES = ['client', 'proprietaire', 'livreur'];

    private const STAFF_ROLES = ['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'];

    public static function defaultPathForUser(?User $user): string
    {
        if ($user?->hasAnyRole(self::CLIENT_ROLES)) {
            return route('client.dashboard');
        }

        if ($user?->hasAnyRole(self::STAFF_ROLES)) {
            return route('dashboard');
        }

        return route('home');
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

        return in_array($normalizedPath, ['/', '/contact', '/help'], true);
    }
}
