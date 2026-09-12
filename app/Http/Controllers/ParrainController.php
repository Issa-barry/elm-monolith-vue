<?php

namespace App\Http\Controllers;

use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Vehicule;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rattachement d'un parrain (Personne) à un véhicule — phase 1 uniquement : pas de commission,
 * pas d'historique (cf. docs/parrainage-vehicule.md). Le parrain est un rôle léger porté par
 * Personne, calqué sur Proprietaire — jamais une identité dupliquée : la recherche par téléphone
 * (rechercherTelephone) est le point d'entrée obligatoire avant toute création, pour que
 * ParrainDialog.vue ne puisse jamais soumettre une création sans être passé par cette recherche.
 */
class ParrainController extends Controller
{
    use PhoneHandlerTrait;

    /**
     * Recherche une Personne existante de l'organisation par téléphone normalisé. Lecture seule,
     * ne crée jamais rien — l'appelant décide ensuite de réutiliser (store avec personne_id) ou
     * de créer (store avec les champs d'identité).
     */
    public function rechercherTelephone(Request $request, Vehicule $vehicule): JsonResponse
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

    /**
     * Associe un parrain au véhicule : soit une Personne déjà trouvée par
     * rechercherTelephone() (personne_id fourni, jamais re-résolue par téléphone), soit une
     * nouvelle identité à résoudre/créer via Personne::resoudreOuCreer(). Réutilise le Parrain
     * existant de cette Personne s'il y en a déjà un — une même personne peut légitimement
     * parrainer plusieurs véhicules, jamais de doublon de rôle Parrain pour une même Personne.
     */
    public function store(Request $request, Vehicule $vehicule): RedirectResponse
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
            ], $this->validationMessages());

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

    /**
     * Modifie l'identité du parrain déjà rattaché, en place — jamais de re-résolution par
     * téléphone (même principe que ResolutionIdentiteTiersTrait/ProprietaireController::update) :
     * éditer un parrain ne doit jamais le rattacher silencieusement à une autre Personne
     * existante. Répercuté sur tous les véhicules parrainés par cette même Personne.
     */
    public function update(Request $request, Vehicule $vehicule): RedirectResponse
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
        ], $this->validationMessages());

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

    private function validationMessages(): array
    {
        return [
            'nom_complet.required' => 'Le nom complet est obligatoire.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ];
    }
}
