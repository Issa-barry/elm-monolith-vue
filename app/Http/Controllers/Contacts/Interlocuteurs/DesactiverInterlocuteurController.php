<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\InterlocuteurService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Désactive un interlocuteur (ADR 0060, lot 4) : le lien est conservé avec sa date et son auteur,
 * il perd le rôle de principal ; rien n'est supprimé, `acces_commissions` n'est pas modifié.
 */
class DesactiverInterlocuteurController extends Controller
{
    use ControleAccesInterlocuteurs;

    public function __invoke(Request $request, EntrepriseTierce $entreprise, EntrepriseInterlocuteur $interlocuteur, InterlocuteurService $interlocuteurs): RedirectResponse
    {
        $this->controlerAcces($request->user(), $entreprise);

        $interlocuteurs->desactiver($interlocuteur, $request->user());

        return $this->retourFiche($entreprise, 'Interlocuteur désactivé.');
    }
}
