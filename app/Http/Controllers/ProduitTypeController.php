<?php

namespace App\Http\Controllers;

use App\Enums\ProduitTypeStatut;
use App\Http\Requests\Produits\StoreProduitTypeRequest;
use App\Http\Requests\Produits\UpdateProduitTypeRequest;
use App\Models\ProduitType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProduitTypeController extends Controller
{
    /**
     * Champs qui définissent le COMPORTEMENT du type (pas juste son affichage) — protégés dès
     * que le type est utilisé par au moins un produit, pour ne jamais rendre incohérent le
     * fonctionnement (stock, prix, marge) de produits déjà créés sous ce type. Cf. décision
     * produit : modification structurelle refusée avec message explicite plutôt que silencieuse
     * ou nécessitant une opération dédiée.
     */
    private const CHAMPS_STRUCTURELS = [
        'gere_stock', 'vendable', 'achetable',
        'prix_achat_requis', 'prix_usine_requis', 'prix_vente_requis',
        'champ_prix_reference',
    ];

    public function index(): Response
    {
        $this->authorize('viewAny', ProduitType::class);

        $orgId = auth()->user()->organization_id;

        $types = ProduitType::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (ProduitType $t) => [
                'id' => $t->id,
                'nom' => $t->nom,
                'code' => $t->code,
                'statut' => $t->statut->value,
                'statut_label' => $t->statut->label(),
                'gere_stock' => $t->gere_stock,
                'vendable' => $t->vendable,
                'achetable' => $t->achetable,
                'prix_achat_requis' => $t->prix_achat_requis,
                'prix_usine_requis' => $t->prix_usine_requis,
                'prix_vente_requis' => $t->prix_vente_requis,
                'champ_prix_reference' => $t->champ_prix_reference,
                'position' => $t->position,
                'produits_count' => $t->produits_count,
                'is_used' => $t->produits_count > 0,
            ]);

        return Inertia::render('Produits/Types/Index', [
            'types' => $types,
            'statuts' => ProduitTypeStatut::options(),
        ]);
    }

    public function store(StoreProduitTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', ProduitType::class);

        $type = ProduitType::create([...$request->validated(), 'organization_id' => auth()->user()->organization_id]);

        return back()
            ->with('success', 'Type de produit créé avec succès.')
            ->with('created_produit_type_id', $type->id);
    }

    public function update(UpdateProduitTypeRequest $request, ProduitType $type): RedirectResponse
    {
        $this->authorize('update', $type);

        $donnees = $request->validated();

        if ($type->is_used) {
            foreach (self::CHAMPS_STRUCTURELS as $champ) {
                if (array_key_exists($champ, $donnees) && $donnees[$champ] != $type->{$champ}) {
                    return back()->withErrors([
                        'structure' => "Ce type est utilisé par au moins un produit : sa structure (gestion du stock, prix requis, règle de marge) ne peut plus être modifiée pour ne pas rendre incohérents les produits existants. Seuls le nom, le statut et l'ordre restent modifiables — créez un nouveau type si vous avez besoin d'un comportement différent.",
                    ]);
                }
            }
        }

        $type->update($donnees);

        return back()->with('success', 'Type de produit mis à jour avec succès.');
    }

    public function toggle(ProduitType $type): RedirectResponse
    {
        $this->authorize('update', $type);

        $type->update([
            'statut' => $type->statut === ProduitTypeStatut::ACTIF ? ProduitTypeStatut::INACTIF : ProduitTypeStatut::ACTIF,
        ]);

        // Désactiver un type n'affecte jamais les produits déjà créés sous ce type — ça empêche
        // uniquement sa sélection pour un NOUVEAU produit (cf. StoreProduitRequest, qui filtre
        // sur statut=actif).
        $label = $type->statut === ProduitTypeStatut::ACTIF ? 'activé' : 'désactivé';

        return back()->with('success', "Type « {$type->nom} » {$label}.");
    }

    public function destroy(ProduitType $type): RedirectResponse
    {
        $this->authorize('delete', $type);

        if ($type->is_used) {
            return back()->withErrors([
                'delete' => 'Ce type est utilisé par des produits existants. Désactivez-le plutôt que de le supprimer.',
            ]);
        }

        $type->delete();

        return back()->with('success', 'Type de produit supprimé.');
    }
}
