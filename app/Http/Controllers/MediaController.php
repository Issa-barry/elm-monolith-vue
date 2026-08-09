<?php

namespace App\Http\Controllers;

use App\Models\Produit;
use App\Models\ProduitMedia;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Galerie photo d'un produit (indépendante du formulaire principal Produit) : upload,
 * réordonnancement, image principale, suppression, et association d'un média à un lot de
 * variantes (cf. MediaService::assignerAuxVariantes() — plusieurs variantes peuvent partager
 * la même photo, jamais de duplication physique par variante).
 */
class MediaController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function store(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:2048'],
        ]);

        $this->mediaService->ajouter($produit, $data['images']);

        return back()->with('success', 'Photo(s) ajoutée(s) à la galerie.');
    }

    public function definirPrincipale(Produit $produit, ProduitMedia $media): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($media->produit_id === $produit->id, 404);

        $this->mediaService->definirPrincipale($produit, $media->id);

        return back()->with('success', 'Image principale mise à jour.');
    }

    public function reordonner(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'ordre' => ['required', 'array'],
            'ordre.*' => ['required', 'string'],
        ]);

        $this->mediaService->reordonner($produit, $data['ordre']);

        return back()->with('success', 'Ordre des photos mis à jour.');
    }

    public function destroy(Produit $produit, ProduitMedia $media): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($media->produit_id === $produit->id, 404);

        $this->mediaService->supprimer($media);

        return back()->with('success', 'Photo supprimée. Les variantes qui l\'utilisaient reprennent l\'image principale du produit.');
    }

    public function assignerVariantes(Request $request, Produit $produit, ProduitMedia $media): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($media->produit_id === $produit->id, 404);

        $data = $request->validate([
            'variante_ids' => ['required', 'array', 'min:1'],
            'variante_ids.*' => ['required', 'string', Rule::exists('produit_variantes', 'id')->where('produit_id', $produit->id)],
        ]);

        $nb = $this->mediaService->assignerAuxVariantes($media, $data['variante_ids']);

        return back()->with('success', "Image associée à {$nb} variante(s).");
    }
}
