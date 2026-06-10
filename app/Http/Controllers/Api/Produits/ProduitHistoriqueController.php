<?php

namespace App\Http\Controllers\Api\Produits;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MouvementStock;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduitHistoriqueController extends Controller
{
    public function __invoke(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $mouvements = MouvementStock::where('produit_id', $produit->id)
            ->with('createur:id,prenom,nom')
            ->orderByDesc('created_at')
            ->take(200)
            ->get()
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'createur' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'created_at' => $m->created_at?->toISOString(),
                'is_initial' => false,
            ])
            ->toArray();

        // Stock initial dérivé de l'audit de création (aucun MouvementStock n'est créé à la création du produit)
        $creation = AuditLog::where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->where('event_code', 'CREATED')
            ->first();

        if ($creation && isset($creation->new_values['qte_stock']) && (float) $creation->new_values['qte_stock'] > 0) {
            $mouvements[] = [
                'id' => 'initial-'.$produit->id,
                'type' => 'entree',
                'quantite' => (int) $creation->new_values['qte_stock'],
                'stock_avant' => 0,
                'stock_apres' => (int) $creation->new_values['qte_stock'],
                'notes' => 'Stock initial — création du produit',
                'createur' => $creation->actor_name_snapshot,
                'created_at' => $creation->created_at?->toISOString(),
                'is_initial' => true,
            ];
        }

        // Historique global — tous les événements audit du produit
        $historique = AuditLog::where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        return response()->json([
            'mouvements' => $mouvements,
            'historique' => $historique,
        ]);
    }
}
