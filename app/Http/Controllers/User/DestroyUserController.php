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

        // Un support dédié (caisse ou compte Mobile Money) ACTIF ou EN BROUILLON pointe sur cet
        // utilisateur : le supprimer le transformerait silencieusement en support d'agence (l'argent
        // d'un support actif entrerait dans le disponible, un brouillon deviendrait un support d'agence
        // à valider). Un support déjà désactivé est forcément vide (cf.
        // CaisseAgentService::mettreAJour()) : il ne bloque pas.
        $supports = CompteTresorerie::where('agent_id', $user->id)->nonDesactives()->get(['id', 'type', 'agent_id']);
        if ($supports->contains(fn (CompteTresorerie $s) => $s->isCaisseDediee())) {
            return redirect()->route('users.index')
                ->with('error', "{$user->name} est responsable d'une caisse dédiée : versez son solde puis désactivez cette caisse (ou validez-la puis désactivez-la si elle est encore en brouillon) dans Trésorerie > Supports avant de supprimer le compte.");
        }
        if ($supports->isNotEmpty()) {
            return redirect()->route('users.index')
                ->with('error', "{$user->name} est titulaire d'un compte Mobile Money dédié : désactivez ce compte (ou validez-le puis désactivez-le s'il est encore en brouillon) dans Trésorerie > Supports avant de supprimer le compte.");
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "{$name} a été supprimé.");
    }
}
