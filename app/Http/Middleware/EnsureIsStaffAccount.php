<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remplace la garde `role:super_admin|admin_entreprise|manager|commerciale|comptable` qui
 * enveloppait la quasi-totalité de /backoffice/* (routes/web.php) — cette liste figée bloquait
 * tout rôle personnalisé créé via RoleController, quelles que soient ses permissions (correction
 * du 2026-08-21, cf. plan §4). Exclusion structurelle plutôt qu'allowlist : seuls les 3 rôles
 * strictement externes (portail client séparé, cf. `role:client|proprietaire|livreur` sur le
 * groupe `client.`) sont refusés — tout le reste (rôles système restants et TOUT rôle
 * personnalisé d'organisation) accède au back-office, les permissions fines de chaque écran
 * restant gérées par les Policies/permissions existantes, inchangées.
 */
class EnsureIsStaffAccount
{
    private const EXTERNAL_ROLES = ['client', 'proprietaire', 'livreur'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null || $user->hasAnyRole(self::EXTERNAL_ROLES), 403);

        return $next($request);
    }
}
