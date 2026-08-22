<?php

namespace App\Http\Controllers;

use App\Enums\CategorieTarifaireVehicule;
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
                'is_active' => $typeVehicule->is_active,
            ],
            'categoriesTarifaires' => CategorieTarifaireVehicule::options(),
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
            'is_active' => 'boolean',
        ], $this->messages());

        $typeVehicule->update($data);

        return redirect()->route('type-vehicules.index')
            ->with('success', 'Type de véhicule mis à jour.');
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
