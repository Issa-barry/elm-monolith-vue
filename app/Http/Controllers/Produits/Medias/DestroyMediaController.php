<?php

namespace App\Http\Controllers\Produits\Medias;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitMedia;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;

class DestroyMediaController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function __invoke(Produit $produit, ProduitMedia $media): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($media->produit_id === $produit->id, 404);

        $this->mediaService->supprimer($media);

        return back()->with('success', 'Photo supprimée. Les variantes qui l\'utilisaient reprennent l\'image principale du produit.');
    }
}
