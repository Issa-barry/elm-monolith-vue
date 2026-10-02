<?php

namespace App\Http\Controllers\Produits;

use App\Enums\AuditEvent;
use App\Enums\ProduitStatut;
use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class ArchiverProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
    ) {}

    public function __invoke(Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::UPDATED,
            auth()->user(),
            ['statut' => $produit->statut?->value],
            ['statut' => ProduitStatut::ARCHIVE->value],
        );

        $produit->update(['statut' => ProduitStatut::ARCHIVE]);

        return redirect()->back()->with('success', "{$produit->nom} a été archivé.");
    }
}
