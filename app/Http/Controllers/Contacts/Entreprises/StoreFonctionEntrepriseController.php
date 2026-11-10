<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreFonctionContactRequest;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;

/**
 * Ajout explicite d'une fonction à une Entreprise existante (ADR 0060, lot 3). Une fiche non
 * visible du lecteur, ou d'une autre organisation, reste introuvable (404).
 */
class StoreFonctionEntrepriseController extends Controller
{
    public function __invoke(StoreFonctionContactRequest $request, EntrepriseTierce $entreprise, VisibiliteContacts $visibilite, FonctionsContactEcriture $fonctions): RedirectResponse
    {
        abort_unless($visibilite->entrepriseVisible($entreprise, $request->user()), 404);

        $fonctions->ajouter($entreprise, $request->input('fonction'), $request->donneesFonction(), $request->user());

        return redirect()->route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'fonctions'])
            ->with('success', 'Fonction ajoutée.');
    }
}
