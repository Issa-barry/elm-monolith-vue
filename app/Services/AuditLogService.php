<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Persist one audit entry.
     */
    public function record(
        Model $auditable,
        AuditEvent $event,
        ?User $actor,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $meta = [],
    ): AuditLog {
        return AuditLog::create([
            'organization_id' => $auditable->organization_id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'event_code' => $event->value,
            'event_label' => $event->label(),
            'actor_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Compute a diff between two commande snapshots.
     *
     * Returns [$oldDiff, $newDiff] — each is null when nothing changed in its side.
     * Only fields that actually changed are included.
     */
    public function diff(array $before, array $after): array
    {
        $scalarKeys = ['vehicule_id', 'vehicule_nom', 'client_id', 'client_nom', 'total_commande', 'statut'];
        $oldDiff = [];
        $newDiff = [];

        foreach ($scalarKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;

            // Normalize numbers to avoid "5000" vs "5000.0" false positives
            $normalize = fn ($v) => is_numeric($v) ? rtrim(number_format((float) $v, 2, '.', ''), '0') : (string) ($v ?? '');

            if ($normalize($oldVal) !== $normalize($newVal)) {
                $oldDiff[$key] = $oldVal;
                $newDiff[$key] = $newVal;
            }
        }

        // Compare lignes by normalizing to a stable, sorted representation
        $normalizeLignes = fn (array $lignes): array => collect($lignes)
            ->sortBy('produit_id')
            ->map(fn ($l) => [
                'produit_id' => (int) ($l['produit_id'] ?? 0),
                'produit_nom' => (string) ($l['produit_nom'] ?? ''),
                'qte' => (int) ($l['qte'] ?? 0),
                'prix_vente_snapshot' => (float) ($l['prix_vente_snapshot'] ?? 0),
            ])
            ->values()
            ->all();

        $beforeLignes = $normalizeLignes($before['lignes'] ?? []);
        $afterLignes = $normalizeLignes($after['lignes'] ?? []);

        if (json_encode($beforeLignes) !== json_encode($afterLignes)) {
            $oldDiff['lignes'] = $before['lignes'] ?? [];
            $newDiff['lignes'] = $after['lignes'] ?? [];
        }

        return [empty($oldDiff) ? null : $oldDiff, empty($newDiff) ? null : $newDiff];
    }
}
