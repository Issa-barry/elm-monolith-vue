<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Services\Rh\AbsenceService;
use App\Support\Rh\AbsencePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Détail d'une absence (JSON) pour la fenêtre « Absence » de la liste et de la fiche salarié, et
 * pour le lien « Voir l'absence existante » d'un chevauchement.
 */
class ShowAbsenceController extends Controller
{
    public function __invoke(Request $request, Absence $absence): JsonResponse
    {
        $this->authorize('view', $absence);
        // Contrôle explicite : le Gate::before du super administrateur ne vérifie ni l'organisation ni l'agence.
        abort_unless(AbsenceService::peutConsulter($request->user(), $absence), 403);

        return response()->json((new AbsencePresenter($request->user()))->detail($absence));
    }
}
