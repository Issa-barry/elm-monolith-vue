<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class DestroyUserController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        abort_if(auth()->id() === $user->id, 403, 'Vous ne pouvez pas supprimer votre propre compte.');

        // Une caisse dédiée ACTIVE ou EN BROUILLON pointe sur cet utilisateur : le supprimer la
        // transformerait silencieusement en support d'agence (l'argent d'une caisse active entrerait
        // dans le disponible, un brouillon deviendrait un support d'agence à valider). Une caisse
        // déjà désactivée est forcément vide (cf. CaisseAgentService::mettreAJour()) : elle ne bloque pas.
        if (CompteTresorerie::where('agent_id', $user->id)->where(fn ($q) => $q->actifs()->orWhereNull('valide_le'))->exists()) {
            return redirect()->route('users.index')
                ->with('error', "{$user->name} est responsable d'une caisse dédiée : versez son solde puis désactivez cette caisse (ou validez-la puis désactivez-la si elle est encore en brouillon) dans Trésorerie > Supports avant de supprimer le compte.");
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "{$name} a été supprimé.");
    }
}
