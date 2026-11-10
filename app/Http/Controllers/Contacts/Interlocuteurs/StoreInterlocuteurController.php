<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreInterlocuteurRequest;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Services\Contacts\InterlocuteurService;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Ajout d'un interlocuteur (ADR 0060, lot 4). Une personne déjà connue n'est rattachée qu'avec sa
 * confirmation explicite (`personne_confirmee_id`), et seulement si elle est visible du lecteur :
 * un id deviné d'une personne non visible reçoit le message neutre, jamais un rattachement.
 */
class StoreInterlocuteurController extends Controller
{
    use ControleAccesInterlocuteurs;

    public function __invoke(StoreInterlocuteurRequest $request, EntrepriseTierce $entreprise, InterlocuteurService $interlocuteurs, VisibiliteContacts $visibilite): RedirectResponse
    {
        $user = $request->user();
        $this->controlerAcces($user, $entreprise);

        $donnees = $request->donnees();
        $confirmee = $request->input('personne_confirmee_id') ?: null;

        // Personne non visible, qu'elle porte le numéro saisi ou soit désignée par un id : message
        // neutre, avant que le service ne puisse citer son nom (« déjà celui de … »).
        $connue = $interlocuteurs->personneConnue($entreprise, $donnees['telephone']);
        $designee = $confirmee !== null ? Personne::find($confirmee) : null;
        if (($connue && ! $visibilite->personneVisible($connue, $user))
            || ($confirmee !== null && (! $designee || ! $visibilite->personneVisible($designee, $user)))) {
            throw ValidationException::withMessages(['telephone' => MessagesContacts::NUMERO_NON_VISIBLE]);
        }

        $interlocuteurs->ajouter($entreprise, $donnees, $user, $confirmee, $request->boolean('principal'));

        return $this->retourFiche($entreprise, 'Interlocuteur ajouté.');
    }
}
