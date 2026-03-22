<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Produit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProduitController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p) => [
                'id'           => $p->id,
                'nom'          => $p->nom,
                'code_interne' => $p->code_interne,
                'type'         => $p->type?->value,
                'type_label'  => $p->type?->label(),
                'statut'      => $p->statut?->value,
                'statut_label'=> $p->statut?->label(),
                'image_url'   => $p->image_url,
                'prix_vente'  => $p->prix_vente,
                'prix_usine'  => $p->prix_usine,
                'prix_achat'  => $p->prix_achat,
                'qte_stock'   => $p->qte_stock,
                'is_critique' => $p->is_critique,
                'in_stock'    => $p->in_stock,
                'is_low_stock'=> $p->is_low_stock,
                'has_stock'   => $p->type?->hasStock() ?? true,
            ]);

        return Inertia::render('Produits/Index', [
            'produits' => $produits,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Produit::class);

        return Inertia::render('Produits/Create', [
            'types'   => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Produit::class);

        $data = $request->validate([
            'nom'                => 'required|string|max:255',
            'code_fournisseur'   => 'nullable|string|max:100',
            'type'               => 'required|in:' . implode(',', ProduitType::values()),
            'statut'             => 'required|in:' . implode(',', ProduitStatut::values()),
            'prix_usine'         => 'nullable|integer|min:0',
            'prix_vente'         => 'nullable|integer|min:0',
            'prix_achat'         => 'nullable|integer|min:0',
            'cout'               => 'nullable|integer|min:0',
            'qte_stock'          => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description'        => 'nullable|string',
            'is_critique'        => 'boolean',
            'image'              => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $data['image_url'] = '/storage/' . $request->file('image')->store('produits', 'public');
        }
        unset($data['image']);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        Produit::create([
            ...$data,
            'organization_id' => $orgId,
        ]);

        return redirect()->route('produits.index')
            ->with('success', 'Produit créé avec succès.');
    }

    public function edit(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id'                 => $produit->id,
                'nom'                => $produit->nom,
                'code_interne'       => $produit->code_interne,
                'code_fournisseur'   => $produit->code_fournisseur,
                'image_url'          => $produit->image_url,
                'type'               => $produit->type?->value,
                'statut'             => $produit->statut?->value,
                'prix_usine'         => $produit->prix_usine,
                'prix_vente'         => $produit->prix_vente,
                'prix_achat'         => $produit->prix_achat,
                'cout'               => $produit->cout,
                'qte_stock'          => $produit->qte_stock,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'description'        => $produit->description,
                'is_critique'        => $produit->is_critique,
            ],
            'types'   => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'nom'                => 'required|string|max:255',
            'code_fournisseur'   => 'nullable|string|max:100',
            'type'               => 'required|in:' . implode(',', ProduitType::values()),
            'statut'             => 'required|in:' . implode(',', ProduitStatut::values()),
            'prix_usine'         => 'nullable|integer|min:0',
            'prix_vente'         => 'nullable|integer|min:0',
            'prix_achat'         => 'nullable|integer|min:0',
            'cout'               => 'nullable|integer|min:0',
            'qte_stock'          => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description'        => 'nullable|string',
            'is_critique'        => 'boolean',
            'image'              => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($produit->image_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $produit->image_url));
            }
            $data['image_url'] = '/storage/' . $request->file('image')->store('produits', 'public');
        }
        unset($data['image']);

        $produit->update($data);

        return redirect()->route('produits.index')
            ->with('success', 'Produit mis à jour avec succès.');
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        $this->authorize('delete', $produit);

        $produit->delete();

        return redirect()->route('produits.index')
            ->with('success', 'Produit supprimé.');
    }
}
