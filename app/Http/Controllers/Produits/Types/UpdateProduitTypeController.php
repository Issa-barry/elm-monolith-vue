<?php

namespace App\Http\Controllers\Produits\Types;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\UpdateProduitTypeRequest;
use App\Models\ProduitType;
use Illuminate\Http\RedirectResponse;

class UpdateProduitTypeController extends Controller
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

    public function __invoke(UpdateProduitTypeRequest $request, ProduitType $type): RedirectResponse
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
}
