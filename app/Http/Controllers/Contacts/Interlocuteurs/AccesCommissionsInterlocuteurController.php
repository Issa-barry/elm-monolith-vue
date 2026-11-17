<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\InterlocuteurService;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Autorise ou retire la consultation des commissions de l'entreprise par un interlocuteur (COMPTE-006,
 * CONTACT-026). Permission `rattachements-comptes.gerer` (pas `contacts.gerer_interlocuteurs` : ce droit
 * ouvre l'accès à des montants), puis entreprise visible ; un interlocuteur d'une autre entreprise
 * répond 404 (scopeBindings).
 */
class AccesCommissionsInterlocuteurController extends Controller
{
    public function __invoke(Request $request, EntrepriseTierce $entreprise, EntrepriseInterlocuteur $interlocuteur, InterlocuteurService $interlocuteurs, VisibiliteContacts $visibilite): RedirectResponse
    {
        $this->authorize('gererAccesCommissions', EntrepriseTierce::class);
        abort_unless($visibilite->entrepriseVisible($entreprise, $request->user()), 404);

        $donnees = $request->validate(['acces_commissions' => ['required', 'boolean']]);
        $acces = (bool) $donnees['acces_commissions'];

        $interlocuteurs->definirAccesCommissions($interlocuteur, $acces, $request->user());

        return redirect()->route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'interlocuteurs'])
            ->with('success', $acces
                ? 'Cet interlocuteur peut consulter les commissions de l’entreprise.'
                : 'Cet interlocuteur ne consulte plus les commissions de l’entreprise.');
    }
}
