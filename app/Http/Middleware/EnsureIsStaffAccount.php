<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remplace la garde `role:super_admin|admin_entreprise|manager|commerciale|comptable` qui
 * enveloppait la quasi-totalité de /backoffice/* (routes/web.php) — cette liste figée bloquait
 * tout rôle personnalisé créé via RoleController, quelles que soient ses permissions (correction
 * du 2026-08-21, cf. plan §4).
 *
 * Règle positive (2026-08-26) : l'accès est accordé dès que le compte porte au
 * moins un rôle staff (système ou personnalisé d'organisation) — cf.
 * User::hasBackofficeAccess(), source de vérité unique partagée avec
 * AuthRedirects. Le cumul avec un rôle client/proprietaire/livreur est autorisé
 * (ex: un admin qui possède lui-même un véhicule, ou commande dans une boutique
 * comme un client normal) : seul un compte n'ayant QUE des rôles externes (ou
 * aucun rôle du tout) est refusé — jamais un compte qui a par ailleurs un rôle
 * staff. Les permissions fines de chaque écran restent gérées par les
 * Policies/permissions existantes, inchangées.
 */
class EnsureIsStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null || ! $user->hasBackofficeAccess(), 403);

        return $next($request);
    }
}
