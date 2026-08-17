<?php

namespace App\Http\Controllers;

use App\Enums\CategorieStatut;
use App\Http\Requests\Produits\StoreCategorieRequest;
use App\Http\Requests\Produits\UpdateCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategorieController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Categorie::class);

        $orgId = auth()->user()->organization_id;

        $categories = Categorie::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (Categorie $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'description' => $c->description,
                'statut' => $c->statut->value,
                'statut_label' => $c->statut->label(),
                'parent_id' => $c->parent_id,
                'position' => $c->position,
                'produits_count' => $c->produits_count,
            ]);

        return Inertia::render('Produits/Categories/Index', [
            'categories' => $categories,
            'statuts' => CategorieStatut::options(),
        ]);
    }

    public function store(StoreCategorieRequest $request): RedirectResponse
    {
        $this->authorize('create', Categorie::class);

        $categorie = Categorie::create([...$request->validated(), 'organization_id' => auth()->user()->organization_id]);

        // Permet à la création rapide depuis un formulaire Produit (modale, sans navigation)
        // de sélectionner automatiquement la catégorie tout juste créée — cf. CategorieSelect.vue.
        return back()
            ->with('success', 'Catégorie créée avec succès.')
            ->with('created_categorie_id', $categorie->id);
    }

    public function update(UpdateCategorieRequest $request, Categorie $categorie): RedirectResponse
    {
        $this->authorize('update', $categorie);

        $categorie->update($request->validated());

        return back()->with('success', 'Catégorie mise à jour avec succès.');
    }

    public function toggle(Categorie $categorie): RedirectResponse
    {
        $this->authorize('update', $categorie);

        $categorie->update([
            'statut' => $categorie->statut === CategorieStatut::ACTIF ? CategorieStatut::INACTIF : CategorieStatut::ACTIF,
        ]);

        $label = $categorie->statut === CategorieStatut::ACTIF ? 'activée' : 'désactivée';

        return back()->with('success', "Catégorie « {$categorie->nom} » {$label}.");
    }

    public function destroy(Categorie $categorie): RedirectResponse
    {
        $this->authorize('delete', $categorie);

        if ($categorie->is_used) {
            return back()->withErrors([
                'delete' => 'Cette catégorie est utilisée par des produits, possède des sous-catégories, ou sert de référence de capacité sur un véhicule. Désactivez-la plutôt que de la supprimer.',
            ]);
        }

        $categorie->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }
}
