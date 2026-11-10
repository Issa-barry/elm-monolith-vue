<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\InterlocuteurService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Désigne l'interlocuteur principal ; l'ancien perd ce rôle (ADR 0060, lot 4, sous verrou). */
class DefinirPrincipalInterlocuteurController extends Controller
{
    use ControleAccesInterlocuteurs;

    public function __invoke(Request $request, EntrepriseTierce $entreprise, EntrepriseInterlocuteur $interlocuteur, InterlocuteurService $interlocuteurs): RedirectResponse
    {
        $this->controlerAcces($request->user(), $entreprise);

        $interlocuteurs->definirPrincipal($interlocuteur, $request->user());

        return $this->retourFiche($entreprise, 'Interlocuteur principal désigné.');
    }
}
