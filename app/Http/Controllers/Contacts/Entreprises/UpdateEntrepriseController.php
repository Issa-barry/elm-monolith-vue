<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdateEntrepriseContactRequest;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\ModificationIdentiteContact;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;

/**
 * Modification de l'identité d'une fiche Entreprise (ADR 0060, lot 7a) : `contacts.update` et
 * entreprise visible (404 sinon, super_admin compris — Gate::before).
 */
class UpdateEntrepriseController extends Controller
{
    public function __invoke(UpdateEntrepriseContactRequest $request, EntrepriseTierce $entreprise, VisibiliteContacts $visibilite, ModificationIdentiteContact $modification): RedirectResponse
    {
        abort_unless($visibilite->entrepriseVisible($entreprise, $request->user()), 404);

        $champs = $modification->modifierEntreprise($request->user(), $entreprise, $request->identite());

        return back()->with('success', $champs === [] ? 'Aucune modification.' : 'Fiche mise à jour.');
    }
}
