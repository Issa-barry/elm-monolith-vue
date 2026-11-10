<?php

namespace App\Http\Controllers\Contacts\Personnes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdatePersonneContactRequest;
use App\Models\Personne;
use App\Services\Contacts\ModificationIdentiteContact;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;

/**
 * Modification de l'identité d'une fiche Personne (ADR 0060, lot 7a) : `contacts.update` et fiche
 * visible (404 sinon, super_admin compris — Gate::before).
 */
class UpdatePersonneController extends Controller
{
    public function __invoke(UpdatePersonneContactRequest $request, Personne $personne, VisibiliteContacts $visibilite, ModificationIdentiteContact $modification): RedirectResponse
    {
        abort_unless($visibilite->personneVisible($personne, $request->user()), 404);

        $champs = $modification->modifierPersonne($request->user(), $personne, $request->identite());

        return back()->with('success', $champs === [] ? 'Aucune modification.' : 'Fiche mise à jour.');
    }
}
