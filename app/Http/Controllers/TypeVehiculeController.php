<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\TypeVehicule;
use App\Models\TypeVehiculeCapacite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'capacite_defaut_bouteilles' => $t->capacite_defaut_bouteilles,
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
            'capacite_defaut_bouteilles' => 'nullable|integer|min:1|max:99999',
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

        $typeVehicule->load('capacites.categorie');

        return Inertia::render('TypeVehicules/Edit', [
            'type' => [
                'id' => $typeVehicule->id,
                'nom' => $typeVehicule->nom,
                'capacite_defaut' => $typeVehicule->capacite_defaut,
                'capacite_defaut_bouteilles' => $typeVehicule->capacite_defaut_bouteilles,
                'unite_capacite' => $typeVehicule->unite_capacite,
                'description' => $typeVehicule->description,
                'is_active' => $typeVehicule->is_active,
                'capacites' => $typeVehicule->capacites->map(fn (TypeVehiculeCapacite $c) => [
                    'id' => $c->id,
                    'categorie_id' => $c->categorie_id,
                    'categorie_nom' => $c->categorie?->nom,
                    'capacite_max' => $c->capacite_max,
                ])->values()->all(),
            ],
            'categories' => $this->categoriesOptions(),
        ]);
    }

    /**
     * Synchronise intégralement les capacités par catégorie par défaut du type — reprises par
     * tout véhicule de ce type sans capacité propre, cf. VehiculeCapaciteService.
     */
    public function syncCapacites(Request $request, TypeVehicule $typeVehicule): RedirectResponse
    {
        $this->authorize('update', $typeVehicule);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'capacites' => 'array',
            'capacites.*.categorie_id' => [
                'required', 'string',
                Rule::exists('categories', 'id')->where('organization_id', $orgId),
                'distinct',
            ],
            'capacites.*.capacite_max' => 'required|integer|min:1|max:99999',
        ], [
            'capacites.*.categorie_id.required' => 'La catégorie est obligatoire.',
            'capacites.*.categorie_id.exists' => 'Catégorie invalide.',
            'capacites.*.categorie_id.distinct' => 'Chaque catégorie ne peut avoir qu\'une seule ligne de capacité.',
            'capacites.*.capacite_max.required' => 'La capacité est obligatoire.',
            'capacites.*.capacite_max.min' => 'La capacité doit être supérieure à 0.',
        ]);

        DB::transaction(function () use ($data, $typeVehicule, $orgId) {
            $typeVehicule->capacites()->delete();
            foreach ($data['capacites'] ?? [] as $ligne) {
                $typeVehicule->capacites()->create([
                    'organization_id' => $orgId,
                    'categorie_id' => $ligne['categorie_id'],
                    'capacite_max' => $ligne['capacite_max'],
                ]);
            }
        });

        return redirect()->route('type-vehicules.edit', $typeVehicule)
            ->with('success', 'Capacités mises à jour.');
    }

    private function categoriesOptions(): array
    {
        return Categorie::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Categorie $c) => ['value' => $c->id, 'label' => $c->nom])
            ->toArray();
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
            'capacite_defaut_bouteilles' => 'nullable|integer|min:1|max:99999',
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
