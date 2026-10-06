<?php

namespace App\Http\Controllers\Produits\Medias;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitMedia;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Association d'un média à un lot de variantes (cf. MediaService::assignerAuxVariantes() —
 * plusieurs variantes peuvent partager la même photo, jamais de duplication physique par
 * variante).
 */
class AssignerVariantesMediaController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function __invoke(Request $request, Produit $produit, ProduitMedia $media): RedirectResponse
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
