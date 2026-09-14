<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\Vehicule;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recherche une Personne existante de l'organisation par téléphone normalisé. Lecture seule, ne
 * crée jamais rien — l'appelant décide ensuite de réutiliser (StoreParrainController avec
 * personne_id) ou de créer (avec les champs d'identité). Point d'entrée obligatoire avant toute
 * création, pour que ParrainDialog.vue ne puisse jamais soumettre une création sans être passé
 * par cette recherche (cf. docs/parrainage-vehicule.md).
 */
class RechercherTelephoneParrainController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request, Vehicule $vehicule): JsonResponse
    {
        $this->authorize('update', $vehicule);

        $data = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
        ]);

        $data = $this->resolveCountryData($data);

        // Le champ de saisie du frontend n'envoie jamais que des chiffres locaux, mais
        // l'endpoint reste tolérant à un numéro déjà saisi avec son indicatif (« +224... » ou
        // « +224 666 17 70 01 ») — extrait via splitPhone() (déjà utilisé ailleurs pour
        // pré-remplir un formulaire d'édition), jamais une deuxième logique de normalisation :
        // ces formats doivent tous résoudre vers le même telephone_normalise.
        [$localDigits] = $this->splitPhone($data['telephone'], $data['code_phone_pays'] ?? null, $data['code_pays'], $data['pays'] ?? null);
        $data['telephone'] = $localDigits ?? $data['telephone'];

        $this->validateLocalPhoneLength($data);

        $international = $this->buildInternationalPhone($data['telephone'], $data['code_phone_pays'] ?? null);
        $telephoneNormalise = Personne::normaliserTelephone($international ?? $data['telephone']);

        $personne = Personne::where('organization_id', $vehicule->organization_id)
            ->where('telephone_normalise', $telephoneNormalise)
            ->first();

        return response()->json([
            'found' => (bool) $personne,
            'personne' => $personne ? [
                'id' => $personne->id,
                'nom_complet' => $personne->nom_complet,
                'telephone' => $personne->telephone,
                'code_phone_pays' => $personne->code_phone_pays,
                'code_pays' => $personne->code_pays,
                'pays' => $personne->pays,
                'ville' => $personne->ville,
                'adresse' => $personne->adresse,
            ] : null,
        ]);
    }
}
