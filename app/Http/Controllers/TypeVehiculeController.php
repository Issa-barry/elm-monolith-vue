<?php

namespace App\Http\Controllers;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\Parametre;
use App\Models\TypeVehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD des types de véhicules — classification pure (nom), sans aucune capacité (décision
 * produit du 17/08/2026, cf. VehiculeCapaciteService). La capacité de chargement se règle sur
 * chaque véhicule individuellement (VehiculeController).
 */
class TypeVehiculeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', TypeVehicule::class);

        $types = TypeVehicule::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (TypeVehicule $t) => [
                'id' => $t->id,
                'nom' => $t->nom,
                'description' => $t->description,
                'categorie_tarifaire' => $t->categorie_tarifaire?->value,
                'categorie_tarifaire_label' => $t->categorie_tarifaire?->label(),
                'seuil_derogation_impayes' => $t->seuil_derogation_impayes,
                'is_active' => $t->is_active,
                'vehicules_count' => $t->vehicules()->count(),
            ]);

        return Inertia::render('TypeVehicules/Index', [
            'types' => $types,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', TypeVehicule::class);

        return Inertia::render('TypeVehicules/Create', [
            'categoriesTarifaires' => CategorieTarifaireVehicule::options(),
            'seuilStandardImpayes' => Parametre::getVentesSeuilImpayesMax(auth()->user()->organization_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', TypeVehicule::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => [
                'required', 'string', 'max:100',
                Rule::unique('type_vehicules', 'nom')->where('organization_id', $orgId),
            ],
            'description' => 'nullable|string|max:500',
            'categorie_tarifaire' => ['nullable', Rule::in(CategorieTarifaireVehicule::values())],
            'seuil_derogation_impayes' => $this->seuilDerogationRules($orgId),
            'is_active' => 'boolean',
        ], $this->messages());

        TypeVehicule::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('type-vehicules.index')
            ->with('success', 'Type de véhicule créé avec succès.');
    }

    public function edit(TypeVehicule $typeVehicule): Response
    {
        $this->authorize('update', $typeVehicule);

        return Inertia::render('TypeVehicules/Edit', [
            'type' => [
                'id' => $typeVehicule->id,
                'nom' => $typeVehicule->nom,
                'description' => $typeVehicule->description,
                'categorie_tarifaire' => $typeVehicule->categorie_tarifaire?->value,
                'seuil_derogation_impayes' => $typeVehicule->seuil_derogation_impayes,
                'is_active' => $typeVehicule->is_active,
            ],
            'categoriesTarifaires' => CategorieTarifaireVehicule::options(),
            'seuilStandardImpayes' => Parametre::getVentesSeuilImpayesMax($typeVehicule->organization_id),
        ]);
    }

    public function update(Request $request, TypeVehicule $typeVehicule): RedirectResponse
    {
        $this->authorize('update', $typeVehicule);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => [
                'required', 'string', 'max:100',
                Rule::unique('type_vehicules', 'nom')
                    ->where('organization_id', $orgId)
                    ->ignore($typeVehicule->id),
            ],
            'description' => 'nullable|string|max:500',
            'categorie_tarifaire' => ['nullable', Rule::in(CategorieTarifaireVehicule::values())],
            'seuil_derogation_impayes' => $this->seuilDerogationRules($orgId),
            'is_active' => 'boolean',
        ], $this->messages());

        $typeVehicule->update($data);

        return redirect()->route('type-vehicules.index')
            ->with('success', 'Type de véhicule mis à jour.');
    }

    /**
     * Un seuil dérogatoire n'a de sens que s'il augmente réellement le plafond par rapport au
     * seuil standard des paramètres de vente — sinon la dérogation ne dérogerait à rien
     * (cf. cadrage du 19/08/2026).
     *
     * @return array<int, mixed>
     */
    private function seuilDerogationRules(string $orgId): array
    {
        return [
            'nullable', 'integer', 'min:0', 'max:999999999',
            function (string $attribute, mixed $value, \Closure $fail) use ($orgId) {
                $seuilStandard = Parametre::getVentesSeuilImpayesMax($orgId);
                if ($value !== null && $value < $seuilStandard) {
                    $fail("Le seuil de dérogation doit être supérieur ou égal au seuil standard actuel ({$seuilStandard} GNF).");
                }
            },
        ];
    }

    public function destroy(TypeVehicule $typeVehicule): RedirectResponse
    {
        $this->authorize('delete', $typeVehicule);

        if ($typeVehicule->vehicules()->exists()) {
            return redirect()->route('type-vehicules.index')
                ->with('error', 'Impossible de supprimer ce type : des véhicules lui sont rattachés.');
        }

        $typeVehicule->delete();

        return redirect()->route('type-vehicules.index')
            ->with('success', 'Type de véhicule supprimé.');
    }

    private function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.unique' => 'Ce nom de type est déjà utilisé dans votre organisation.',
            'nom.max' => 'Le nom ne peut pas dépasser 100 caractères.',
        ];
    }
}
