<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Depense;
use Illuminate\Http\JsonResponse;

class HistoriqueDepenseController extends Controller
{
    public function __invoke(Depense $depense): JsonResponse
    {
        abort_unless(auth()->user()->organization_id === $depense->organization_id, 403);
        abort_unless(auth()->user()->can('depenses.read'), 403);

        $logs = AuditLog::where('auditable_type', $depense->getMorphClass())
            ->where('auditable_id', $depense->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'date' => $log->created_at->format('d/m/Y H:i'),
                'acteur' => $log->actor_name_snapshot ?? '—',
                'event_code' => $log->event_code,
                'action' => $log->event_label,
                'description' => $this->buildAuditDescription($log),
            ]);

        return response()->json(['logs' => $logs]);
    }

    private function buildAuditDescription(AuditLog $log): string
    {
        return match ($log->event_code) {
            'created' => 'Dépense créée',
            'updated' => $this->describeUpdate($log),
            'submitted' => 'Soumise pour validation',
            'validated' => 'Dépense validée et imputée',
            'rejected' => 'Rejetée — Motif : '.($log->meta['motif_rejet'] ?? 'non précisé'),
            'deleted' => 'Dépense supprimée',
            'exported' => 'Export '.($log->meta['format'] ?? ''),
            default => $log->event_label,
        };
    }

    private function describeUpdate(AuditLog $log): string
    {
        $newValues = $log->new_values ?? [];
        if (empty($newValues)) {
            return 'Dépense modifiée';
        }

        $labels = [
            'montant' => 'Montant',
            'date_depense' => 'Date',
            'commentaire' => 'Commentaire',
            'statut' => 'Statut',
            'site_id' => 'Site',
            'depense_type_id' => 'Type',
            'beneficiaire_id' => 'Bénéficiaire',
            'motif_rejet' => 'Motif de rejet',
        ];

        $changed = array_values(array_filter(
            array_map(fn ($k) => $labels[$k] ?? null, array_keys($newValues))
        ));

        return empty($changed)
            ? 'Dépense modifiée'
            : 'Modifié : '.implode(', ', $changed);
    }
}
