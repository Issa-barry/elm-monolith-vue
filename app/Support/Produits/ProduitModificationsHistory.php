<?php

namespace App\Support\Produits;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Produit;

/**
 * Historique des modifications métier d'un produit (hors ajustements de stock, cf. l'onglet
 * dédié) — extrait de ProduitController::loadModifications(), partagé entre show() (affichage
 * initial) et historique() (rafraîchissement JSON, filtré par variante/site).
 */
final class ProduitModificationsHistory
{
    public static function pour(Produit $produit): array
    {
        return AuditLog::where('organization_id', $produit->organization_id)
            ->where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->where('event_code', '!=', AuditEvent::STOCK_ADJUSTED->value)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ])
            ->all();
    }
}
