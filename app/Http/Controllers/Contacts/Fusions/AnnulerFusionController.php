<?php

namespace App\Http\Controllers\Contacts\Fusions;

use App\Http\Controllers\Controller;
use App\Models\ContactsFusion;
use App\Models\Personne;
use App\Services\Contacts\Fusion\AnnulationFusionContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Annulation d'une fusion (lot 9b) : restaure ce qui est sûr, signale le reste. */
class AnnulerFusionController extends Controller
{
    public function __invoke(Request $request, ContactsFusion $fusion, AnnulationFusionContacts $annulation): RedirectResponse
    {
        $this->authorize('fusionner', Personne::class);
        abort_unless($fusion->organization_id === $request->user()->organization_id, 404);

        $fusion = $annulation->annuler($fusion, $request->user());

        return redirect()->route('contacts.fusions.show', $fusion)->with('success', $fusion->statut === ContactsFusion::ANNULEE
            ? 'Fusion annulée : tout a été restauré.'
            : 'Fusion annulée en partie : les éléments modifiés depuis restent à traiter (voir la liste).');
    }
}
