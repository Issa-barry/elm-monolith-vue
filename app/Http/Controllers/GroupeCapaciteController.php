<?php

namespace App\Http\Controllers;

use App\Models\GroupeCapacite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD des groupes de capacité (ex: "Sachets", "Bouteilles") — bibliothèque réutilisée à la
 * fois par le rattachement produit (Produit::groupe_capacite_id) et par la capacité véhicule
 * (VehiculeCapacite::groupe_capacite_id). Rattaché à la permission vehicules.update plutôt qu'à
 * une permission dédiée : c'est un réglage opérationnel de flotte, pas une ressource catalogue
 * de plein droit (pas de Policy/permission séparée à faire vivre dans les 4 endroits habituels).
 */
class GroupeCapaciteController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()->can('vehicules.update'), 403);

        $orgId = auth()->user()->organization_id;

        $groupes = GroupeCapacite::where('organization_id', $orgId)
            ->withCount('produits')
            ->orderBy('nom')
            ->get()
            ->map(fn (GroupeCapacite $g) => [
                'id' => $g->id,
                'nom' => $g->nom,
                'produits_count' => $g->produits_count,
            ]);

        return Inertia::render('Vehicules/GroupesCapacite/Index', [
            'groupes' => $groupes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('vehicules.update'), 403);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => [
                'required', 'string', 'max:100',
                Rule::unique('groupes_capacite', 'nom')->where('organization_id', $orgId),
            ],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.unique' => 'Ce groupe de capacité existe déjà.',
        ]);

        $groupe = GroupeCapacite::create([...$data, 'organization_id' => $orgId]);

        // Permet la création rapide depuis un formulaire (produit, véhicule) sans navigation —
        // même mécanisme que created_categorie_id / created_option_catalogue_id.
        return back()
            ->with('success', 'Groupe de capacité créé.')
            ->with('created_groupe_capacite_id', $groupe->id);
    }

    public function update(Request $request, GroupeCapacite $groupeCapacite): RedirectResponse
    {
        abort_unless(auth()->user()->can('vehicules.update'), 403);
        abort_unless($groupeCapacite->organization_id === auth()->user()->organization_id, 403);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => [
                'required', 'string', 'max:100',
                Rule::unique('groupes_capacite', 'nom')->where('organization_id', $orgId)->ignore($groupeCapacite->id),
            ],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.unique' => 'Ce groupe de capacité existe déjà.',
        ]);

        $groupeCapacite->update($data);

        return back()->with('success', 'Groupe de capacité mis à jour.');
    }

    public function destroy(GroupeCapacite $groupeCapacite): RedirectResponse
    {
        abort_unless(auth()->user()->can('vehicules.update'), 403);
        abort_unless($groupeCapacite->organization_id === auth()->user()->organization_id, 403);

        if ($groupeCapacite->is_used) {
            return back()->withErrors([
                'delete' => 'Ce groupe de capacité est utilisé par des produits ou des véhicules. Retirez-le de ces éléments avant de le supprimer.',
            ]);
        }

        $groupeCapacite->delete();

        return back()->with('success', 'Groupe de capacité supprimé.');
    }
}
