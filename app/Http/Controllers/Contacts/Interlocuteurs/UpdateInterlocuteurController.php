<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdateInterlocuteurRequest;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\InterlocuteurService;
use Illuminate\Http\RedirectResponse;

/** Modification d'un interlocuteur : fonction et notes seulement (ADR 0060, lot 4). */
class UpdateInterlocuteurController extends Controller
{
    use ControleAccesInterlocuteurs;

    public function __invoke(UpdateInterlocuteurRequest $request, EntrepriseTierce $entreprise, EntrepriseInterlocuteur $interlocuteur, InterlocuteurService $interlocuteurs): RedirectResponse
    {
        $this->controlerAcces($request->user(), $entreprise);

        $interlocuteurs->modifier($interlocuteur, $request->only(['fonction', 'notes']), $request->user());

        return $this->retourFiche($entreprise, 'Interlocuteur modifié.');
    }
}
