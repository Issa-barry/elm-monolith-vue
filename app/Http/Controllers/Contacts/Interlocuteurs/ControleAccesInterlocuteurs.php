<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Models\EntrepriseTierce;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;

/**
 * Contrôle commun des opérations sur les interlocuteurs (ADR 0060, lot 4) : permission
 * `contacts.gerer_interlocuteurs`, puis entreprise visible du lecteur. Une entreprise non visible ou
 * d'une autre organisation répond 404, y compris pour un super_admin (Gate::before ne contourne pas
 * la visibilité, vérifiée ici). L'appartenance de l'interlocuteur à l'entreprise est garantie par la
 * liaison de route limitée (scopeBindings) : un interlocuteur d'une autre entreprise répond 404.
 */
trait ControleAccesInterlocuteurs
{
    private function controlerAcces(User $user, EntrepriseTierce $entreprise): void
    {
        $this->authorize('gererInterlocuteurs', EntrepriseTierce::class);
        abort_unless(app(VisibiliteContacts::class)->entrepriseVisible($entreprise, $user), 404);
    }

    private function retourFiche(EntrepriseTierce $entreprise, string $message): RedirectResponse
    {
        return redirect()->route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'interlocuteurs'])
            ->with('success', $message);
    }
}
