<?php

namespace App\Http\Controllers\Produits\Medias;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReordonnerMediaController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function __invoke(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'ordre' => ['required', 'array'],
            'ordre.*' => ['required', 'string'],
        ]);

        $this->mediaService->reordonner($produit, $data['ordre']);

        return back()->with('success', 'Ordre des photos mis à jour.');
    }
}
