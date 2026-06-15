<?php

namespace App\Http\Controllers;

use App\Models\TypeVehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
                'capacite_defaut' => $t->capacite_defaut,
                'unite_capacite' => $t->unite_capacite,
                'description' => $t->description,
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

        return Inertia::render('TypeVehicules/Create');
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
            'capacite_defaut' => 'required|integer|min:1|max:99999',
            'unite_capacite' => 'required|string|max:20',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], $this->messages($orgId));

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
                'capacite_defaut' => $typeVehicule->capacite_defaut,
                'unite_capacite' => $typeVehicule->unite_capacite,
                'description' => $typeVehicule->description,
                'is_active' => $typeVehicule->is_active,
            ],
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
            'capacite_defaut' => 'required|integer|min:1|max:99999',
            'unite_capacite' => 'required|string|max:20',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], $this->messages($orgId));

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

    private function messages(string $orgId): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.unique' => 'Ce nom de type est déjà utilisé dans votre organisation.',
            'nom.max' => 'Le nom ne peut pas dépasser 100 caractères.',
            'capacite_defaut.required' => 'La capacité par défaut est obligatoire.',
            'capacite_defaut.min' => 'La capacité doit être supérieure à 0.',
            'unite_capacite.required' => "L'unité de capacité est obligatoire.",
        ];
    }
}
