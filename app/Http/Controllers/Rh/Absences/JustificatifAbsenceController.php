<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Services\Rh\AbsenceService;
use App\Services\Rh\JustificatifAbsenceStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Affiche le justificatif d'une absence (disque privé), après contrôle des droits et de l'agence. */
class JustificatifAbsenceController extends Controller
{
    public function __invoke(Request $request, Absence $absence, JustificatifAbsenceStorage $stockage): StreamedResponse
    {
        $this->authorize('view', $absence);
        abort_unless(AbsenceService::peutConsulter($request->user(), $absence), 403);
        abort_if($absence->justificatif_path === null, 404);

        return $stockage->reponse(
            $absence->justificatif_path,
            $absence->justificatif_nom_original ?? 'justificatif',
            $absence->justificatif_mime_type,
        );
    }
}
