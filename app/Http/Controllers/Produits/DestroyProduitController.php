<?php

namespace App\Http\Controllers\Produits;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\AuditLogService;
use App\Services\MediaService;
use App\Support\Produits\ProduitAuditSnapshot;
use Illuminate\Http\RedirectResponse;

class DestroyProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly MediaService $mediaService,
    ) {}

    public function __invoke(Produit $produit): RedirectResponse
    {
        $this->authorize('delete', $produit);

        $this->auditService->record($produit, AuditEvent::DELETED, auth()->user(), ProduitAuditSnapshot::pour($produit->fresh(['variantes', 'fournisseur', 'produitType'])), null);

        foreach ($produit->medias as $media) {
            $this->mediaService->supprimer($media);
        }

        $produit->delete();

        return redirect()->route('produits.index')->with('success', 'Produit supprimé.');
    }
}
