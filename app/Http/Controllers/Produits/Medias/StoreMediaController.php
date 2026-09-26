<?php

namespace App\Http\Controllers\Produits\Medias;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Galerie photo d'un produit (indépendante du formulaire principal Produit) : upload,
 * réordonnancement, image principale, suppression, et association d'un média à un lot de
 * variantes (cf. MediaService::assignerAuxVariantes() — plusieurs variantes peuvent partager
 * la même photo, jamais de duplication physique par variante).
 */
class StoreMediaController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function __invoke(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:2048'],
        ]);

        $this->mediaService->ajouter($produit, $data['images']);

        return back()->with('success', 'Photo(s) ajoutée(s) à la galerie.');
    }
}
