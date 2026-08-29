<?php

namespace App\Http\Middleware;

use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Filet de sécurité pour les sessions déjà authentifiées : si un compte est
     * désactivé ou repasse en pending_validation après connexion (ex: admin qui
     * refuse un compte entre-temps), on le déconnecte immédiatement plutôt que de
     * ne vérifier le statut qu'à la connexion. Symétrique côté API : voir
     * EnsureApiAccountIsActive (guard sanctum, pas de session à invalider).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        $status = $user->isPendingValidation() ? AccountStatus::PendingValidation : AccountStatus::Blocked;
        $message = AccountEligibility::message($status);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
