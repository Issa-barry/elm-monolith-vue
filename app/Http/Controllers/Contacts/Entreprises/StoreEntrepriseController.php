<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreEntrepriseContactRequest;
use App\Services\Contacts\CreationContact;
use Illuminate\Http\RedirectResponse;

/** Création d'une fiche Entreprise et de sa première fonction (ADR 0060, lot 3). */
class StoreEntrepriseController extends Controller
{
    public function __invoke(StoreEntrepriseContactRequest $request, CreationContact $creation): RedirectResponse
    {
        $entreprise = $creation->creerEntreprise($request->identite(), $request->input('fonction'), $request->donneesFonction(), $request->user());

        return redirect()->route('contacts.entreprises.show', $entreprise)
            ->with('success', 'Contact créé.');
    }
}
