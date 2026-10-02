<?php

namespace App\Http\Controllers\Produits\Types;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produits\StoreProduitTypeRequest;
use App\Models\ProduitType;
use Illuminate\Http\RedirectResponse;

class StoreProduitTypeController extends Controller
{
    public function __invoke(StoreProduitTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', ProduitType::class);

        $type = ProduitType::create([...$request->validated(), 'organization_id' => auth()->user()->organization_id]);

        return back()
            ->with('success', 'Type de produit créé avec succès.')
            ->with('created_produit_type_id', $type->id);
    }
}
