<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Vehicule;
use App\Support\Parrainage\ParrainValidationMessages;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Associe un parrain au véhicule : soit une Personne déjà trouvée par
 * RechercherTelephoneParrainController (personne_id fourni, jamais re-résolue par téléphone),
 * soit une nouvelle identité à résoudre/créer via Personne::resoudreOuCreer(). Réutilise le
 * Parrain existant de cette Personne s'il y en a déjà un — une même personne peut légitimement
 * parrainer plusieurs véhicules, jamais de doublon de rôle Parrain pour une même Personne.
 */
class StoreParrainController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);

        $orgId = $vehicule->organization_id;

        if ($request->filled('personne_id')) {
            $data = $request->validate([
                'personne_id' => [
                    'required', 'string',
                    Rule::exists('personnes', 'id')->where('organization_id', $orgId),
                ],
            ], [
                'personne_id.exists' => "Cette personne n'appartient pas à votre organisation.",
            ]);

            $personne = Personne::where('organization_id', $orgId)->findOrFail($data['personne_id']);
        } else {
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

            $personne = Personne::resoudreOuCreer($orgId, [
                'nom_complet' => $data['nom_complet'],
                'telephone' => $data['telephone'],
                'code_pays' => $data['code_pays'],
                'code_phone_pays' => $data['code_phone_pays'] ?? null,
                'pays' => $data['pays'] ?? null,
                'ville' => $data['ville'] ?? null,
                'adresse' => $data['adresse'] ?? null,
            ]);
        }

        $parrain = Parrain::firstOrCreate(
            ['organization_id' => $orgId, 'personne_id' => $personne->id],
            ['is_active' => true],
        );

        $vehicule->update(['parrain_id' => $parrain->id]);

        return redirect()->route('vehicules.show', $vehicule)
            ->with('success', 'Parrain associé au véhicule.');
    }
}
