<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordUserController extends Controller
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $user->update(['password' => $data['password']]);

        // Le même formulaire existe sur la fiche agent (onglet Mot de passe) : on y reste.
        $retour = $request->boolean('depuis_fiche')
            ? route('users.show', ['user' => $user, 'tab' => 'mot-de-passe'])
            : route('users.edit', $user);

        return redirect($retour)
            ->with('success', "Mot de passe de {$user->name} mis à jour.");
    }
}
