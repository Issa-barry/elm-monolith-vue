<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\EnregistrerAbsenceRequest;
use App\Models\Absence;
use App\Services\Rh\AbsenceService;
use Illuminate\Http\RedirectResponse;

/** Enregistre une absence, depuis la liste ou la fiche salarié (docs/rh-absences.md). */
class StoreAbsenceController extends Controller
{
    public function __invoke(EnregistrerAbsenceRequest $request, AbsenceService $service): RedirectResponse
    {
        $this->authorize('create', Absence::class);

        $absence = $service->creer($request->validated(), $request->file('justificatif'), $request->user());

        $jours = $absence->duree();

        return back()->with('success', 'Absence enregistrée ('.$jours.' jour'.($jours > 1 ? 's' : '').').');
    }
}
