<?php

namespace App\Http\Controllers\Produits;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\AuditLogService;
use App\Services\MediaService;
use App\Services\ProduitService;
use App\Support\Produits\ProduitAuditSnapshot;
use App\Support\Produits\ProduitFormValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly ProduitService $produitService,
        private readonly MediaService $mediaService,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('create', Produit::class);

        $data = ProduitFormValidator::valider($request);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        // Transaction englobante : si l'upload d'images échoue (limite dépassée), le produit
        // qui vient d'être créé ne doit pas rester orphelin en base.
        $produit = DB::transaction(function () use ($data, $orgId, $request) {
            $produit = $this->produitService->creer([...$data, 'organization_id' => $orgId]);

            if ($request->hasFile('images')) {
                $this->mediaService->ajouter($produit, $request->file('images'));
            }

            return $produit;
        });

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            auth()->user(),
            null,
            ProduitAuditSnapshot::pour($produit->fresh(['variantes', 'fournisseur', 'produitType'])),
        );

        return redirect()->route('produits.show', $produit)->with('success', 'Produit créé avec succès.');
    }
}
