<?php

namespace App\Http\Controllers\Contacts\Personnes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StorePersonneContactRequest;
use App\Services\Contacts\CreationContact;
use Illuminate\Http\RedirectResponse;

/** Création d'une fiche Personne physique et de sa première fonction (ADR 0060, lot 3). */
class StorePersonneController extends Controller
{
    public function __invoke(StorePersonneContactRequest $request, CreationContact $creation): RedirectResponse
    {
        $personne = $creation->creerPersonne($request->identite(), $request->input('fonction'), $request->donneesFonction(), $request->user());

        return redirect()->route('contacts.personnes.show', $personne)
            ->with('success', 'Contact créé.');
    }
}
