<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Http\Controllers\Comptabilite\HistoriqueActionsController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TypeRemuneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Historique d'un type (création, modifications, validation, statuts), affiché par AuditDrawer. */
class HistoriqueTypeRemunerationController extends Controller
{
    public function __invoke(Request $request, TypeRemuneration $typeRemuneration): JsonResponse
    {
        $this->authorize('view', $typeRemuneration);
        // Contrôle explicite : le Gate::before du super administrateur ne vérifie pas l'organisation.
        abort_if($typeRemuneration->organization_id !== $request->user()->organization_id, 403);

        $logs = AuditLog::where('organization_id', $typeRemuneration->organization_id)
            ->where('auditable_type', $typeRemuneration->getMorphClass())
            ->where('auditable_id', $typeRemuneration->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => HistoriqueActionsController::transformLog($log));

        return response()->json($logs);
    }
}
