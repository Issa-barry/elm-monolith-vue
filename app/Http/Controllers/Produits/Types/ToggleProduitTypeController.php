<?php

namespace App\Http\Controllers\Produits\Types;

use App\Enums\ProduitTypeStatut;
use App\Http\Controllers\Controller;
use App\Models\ProduitType;
use Illuminate\Http\RedirectResponse;

class ToggleProduitTypeController extends Controller
{
    public function __invoke(ProduitType $type): RedirectResponse
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
}
