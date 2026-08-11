<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Support\AuthRedirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Définition obligatoire du mot de passe pour un compte créé avec un mot de passe provisoire
 * (`must_change_password`, cf. `php artisan app:install`) — pas de "mot de passe actuel" requis
 * ici contrairement au changement volontaire depuis les Réglages (Fortify::updatePasswordsUsing),
 * puisque l'objectif est justement de remplacer un secret que l'opérateur d'installation connaît
 * aussi. Voir EnsurePasswordIsNotExpired pour le blocage d'accès tant que ce n'est pas fait.
 */
class ForcePasswordChangeController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()?->must_change_password) {
            return redirect(AuthRedirects::defaultPathForUser($request->user()));
        }

        return Inertia::render('auth/ForcePasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->must_change_password, 403);

        Validator::make($request->all(), [
            'password' => $this->passwordRules(),
        ])->validate();

        $request->user()->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        return redirect(AuthRedirects::defaultPathForUser($request->user()))
            ->with('success', 'Mot de passe défini avec succès.');
    }
}
