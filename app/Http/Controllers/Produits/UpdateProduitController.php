<?php

namespace App\Http\Controllers\Produits;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\MediaService;
use App\Services\ProduitService;
use App\Services\ProduitSeuilAlerteService;
use App\Support\Produits\ProduitAuditSnapshot;
use App\Support\Produits\ProduitFormValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly ProduitService $produitService,
        private readonly MediaService $mediaService,
        private readonly ProduitSeuilAlerteService $seuilAlerteService,
    ) {}

    public function __invoke(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = ProduitFormValidator::valider($request, $produit);
        $seuilsSite = $data['seuils_site'] ?? [];
        $disponibiliteMode = $data['disponibilite_mode'] ?? null;
        $sitesDisponibles = $data['sites_disponibles'] ?? [];
        unset($data['seuils_site'], $data['disponibilite_mode'], $data['sites_disponibles']);

        $oldSnapshot = ProduitAuditSnapshot::pour($produit->fresh(['variantes', 'fournisseur', 'produitType']));

        $produit = DB::transaction(function () use ($produit, $data, $request, $seuilsSite, $disponibiliteMode, $sitesDisponibles) {
            $produit = $this->produitService->mettreAJourSimple($produit, $data);

            if ($request->has('disponibilite_mode')) {
                // "tous" → aucune restriction (repli sur le défaut de colonne, disponible
                // partout) ; "selection" → seuls les sites cochés sont disponibles, tous les
                // autres sites actifs de l'organisation deviennent explicitement indisponibles.
                $this->seuilAlerteService->definirDisponibilitePourSites(
                    $produit,
                    $disponibiliteMode === 'selection' ? $sitesDisponibles : null,
                );
            }

            if ($request->has('seuils_site')) {
                // Ne persiste que pour les sites ACTUELLEMENT actifs — protège contre une
                // désactivation de site survenue entre le chargement du formulaire et la
                // soumission (jamais d'erreur bloquante pour ce cas rare, on ignore juste la
                // ligne périmée).
                $sitesActifsIds = Site::where('organization_id', $produit->organization_id)
                    ->actives()->pluck('id')->map(fn ($id) => (string) $id)->all();

                foreach ($seuilsSite as $ligne) {
                    if (! in_array((string) ($ligne['site_id'] ?? ''), $sitesActifsIds, true)) {
                        continue;
                    }
                    $this->seuilAlerteService->definir($produit, $ligne['site_id'], (bool) ($ligne['actif'] ?? false), $ligne['seuil'] ?? null);
                }
            }

            if ($request->hasFile('images')) {
                $this->mediaService->ajouter($produit, $request->file('images'));
            }

            return $produit;
        });

        $newSnapshot = ProduitAuditSnapshot::pour($produit->fresh(['variantes', 'fournisseur', 'produitType']));

        [$oldDiff, $newDiff] = ProduitAuditSnapshot::diff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($produit, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('produits.show', $produit)->with('success', 'Produit mis à jour avec succès.');
    }
}
