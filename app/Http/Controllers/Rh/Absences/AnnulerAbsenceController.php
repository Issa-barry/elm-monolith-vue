<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Services\Rh\AbsenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Annule une absence sans la supprimer : elle reste consultable avec le statut « Annulée ». */
class AnnulerAbsenceController extends Controller
{
    public function __invoke(Request $request, Absence $absence, AbsenceService $service): RedirectResponse
    {
        $this->authorize('annuler', $absence);
        $data = $request->validate(
            ['motif_annulation' => ['nullable', 'string', 'max:255']],
            ['motif_annulation.max' => 'Le motif d\'annulation ne doit pas dépasser 255 caractères.'],
        );

        $service->annuler($absence, $data['motif_annulation'] ?? null, $request->user());

        return back()->with('success', 'Absence annulée. Elle reste visible dans l\'historique.');
    }
}
