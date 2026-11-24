<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\EnregistrerAbsenceRequest;
use App\Models\Absence;
use App\Services\Rh\AbsenceService;
use Illuminate\Http\RedirectResponse;

/** Modifie le motif, les dates, le commentaire ou le justificatif d'une absence enregistrée. */
class UpdateAbsenceController extends Controller
{
    public function __invoke(EnregistrerAbsenceRequest $request, Absence $absence, AbsenceService $service): RedirectResponse
    {
        $this->authorize('update', $absence);

        $service->modifier(
            $absence,
            $request->validated(),
            $request->file('justificatif'),
            $request->boolean('retirer_justificatif'),
            $request->user(),
        );

        return back()->with('success', 'Absence modifiée.');
    }
}
