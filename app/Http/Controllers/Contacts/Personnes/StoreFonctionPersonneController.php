<?php

namespace App\Http\Controllers\Contacts\Personnes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreFonctionContactRequest;
use App\Models\Personne;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;

/**
 * Ajout explicite d'une fonction à une Personne existante (ADR 0060, lot 3). Une fiche non visible
 * du lecteur, ou d'une autre organisation, reste introuvable (404), y compris pour un super_admin.
 */
class StoreFonctionPersonneController extends Controller
{
    public function __invoke(StoreFonctionContactRequest $request, Personne $personne, VisibiliteContacts $visibilite, FonctionsContactEcriture $fonctions): RedirectResponse
    {
        abort_unless($visibilite->personneVisible($personne, $request->user()), 404);

        $fonctions->ajouter($personne, $request->input('fonction'), $request->donneesFonction(), $request->user());

        return redirect()->route('contacts.personnes.show', ['personne' => $personne, 'tab' => 'fonctions'])
            ->with('success', 'Fonction ajoutée.');
    }
}
