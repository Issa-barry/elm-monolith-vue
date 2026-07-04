<?php

namespace App\Http\Middleware;

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
     * ne vérifier le statut qu'à la connexion.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        $message = $user->isPendingValidation()
            ? 'Votre compte a bien été créé. Il est en attente de validation par un administrateur.'
            : 'Votre compte a été désactivé. Veuillez contacter notre service client pour plus d\'informations.';

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
