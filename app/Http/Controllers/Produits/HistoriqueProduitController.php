<?php

namespace App\Http\Controllers\Produits;

use App\Http\Controllers\Controller;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Site;
use App\Services\MouvementStockMotifService;
use App\Support\Produits\ProduitModificationsHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoriqueProduitController extends Controller
{
    public function __invoke(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $varianteIds = $produit->variantes()->pluck('id');
        $varianteId = $request->string('variante_id')->trim()->toString();

        if ($varianteId !== '') {
            abort_unless($varianteIds->contains($varianteId), 404);
            $varianteIds = collect([$varianteId]);
        }

        $user = $request->user();
        $sitesConsultables = $user->isAdmin()
            ? Site::where('organization_id', $produit->organization_id)->pluck('id')
            : $user->sites()->where('sites.organization_id', $produit->organization_id)->pluck('sites.id');
        $siteId = $request->string('site_id')->trim()->toString();
        if ($siteId !== '') {
            abort_unless($sitesConsultables->contains($siteId), 404);
            $sitesConsultables = collect([$siteId]);
        }

        $baseQuery = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->where('organization_id', $produit->organization_id)
            ->whereIn('site_id', $sitesConsultables);

        $motifsDisponibles = MouvementStockMotifService::optionsDisponibles(clone $baseQuery);

        $motif = $request->string('motif')->trim()->toString();
        $query = clone $baseQuery;
        if ($motif !== '') {
            $query = MouvementStockMotifService::appliquerFiltre($query, $motif);
        }

        $mouvements = $query
            ->with(['createur:id,personne_id', 'createur.personne', 'site:id,nom,code'])
            ->orderByDesc('created_at')
            ->take(200)
            ->get();

        $ajustements = MouvementStockMotifService::annoter($mouvements)
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'motif_type' => $m->motif_type,
                'motif_label' => $m->motif_label,
                'site_nom' => $m->site?->nom,
                'site_code' => $m->site?->code,
                'createur_nom' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'created_at' => $m->created_at?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'ajustements' => $ajustements,
            'modifications' => ProduitModificationsHistory::pour($produit),
            'motifs_disponibles' => $motifsDisponibles,
        ]);
    }
}
