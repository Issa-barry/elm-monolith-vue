<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\Vehicule;
use App\Support\Parrainage\ParrainValidationMessages;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Modifie l'identité du parrain déjà rattaché, en place — jamais de re-résolution par téléphone
 * (même principe que ResolutionIdentiteTiersTrait/ProprietaireController::update) : éditer un
 * parrain ne doit jamais le rattacher silencieusement à une autre Personne existante. Répercuté
 * sur tous les véhicules parrainés par cette même Personne.
 */
class UpdateParrainController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_if(! $vehicule->parrain_id, 404, "Ce véhicule n'a pas de parrain à modifier.");

        $parrain = $vehicule->parrain;

        $data = $request->validate([
            'nom_complet' => 'required|string|max:255',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
        ], ParrainValidationMessages::pour());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength($data);
        $data = $this->normalizePersonData($data);

        Personne::assertTelephoneDisponible($vehicule->organization_id, $data['telephone'], $parrain->personne_id);

        $parrain->personne->update([
            'nom_complet' => $data['nom_complet'],
            'telephone' => $data['telephone'],
            'telephone_normalise' => Personne::normaliserTelephone($data['telephone']),
            'code_pays' => $data['code_pays'],
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
            'pays' => $data['pays'] ?? null,
            'ville' => $data['ville'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ]);

        return redirect()->route('vehicules.show', $vehicule)
            ->with('success', 'Parrain mis à jour avec succès.');
    }
}
