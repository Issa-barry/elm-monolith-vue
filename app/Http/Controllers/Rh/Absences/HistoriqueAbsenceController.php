<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Comptabilite\HistoriqueActionsController;
use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\AuditLog;
use App\Services\Rh\AbsenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Historique d'une absence (création, modifications, annulation) pour AuditDrawer.vue. */
class HistoriqueAbsenceController extends Controller
{
    public function __invoke(Request $request, Absence $absence): JsonResponse
    {
        $this->authorize('view', $absence);
        abort_unless(AbsenceService::peutConsulter($request->user(), $absence), 403);

        $logs = AuditLog::where('organization_id', $absence->organization_id)
            ->where('auditable_type', $absence->getMorphClass())
            ->where('auditable_id', $absence->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => HistoriqueActionsController::transformLog($log));

        return response()->json($logs);
    }
}
