<?php

namespace App\Http\Controllers\Produits\Types;

use App\Http\Controllers\Controller;
use App\Models\ProduitType;
use Illuminate\Http\RedirectResponse;

class DestroyProduitTypeController extends Controller
{
    public function __invoke(ProduitType $type): RedirectResponse
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
