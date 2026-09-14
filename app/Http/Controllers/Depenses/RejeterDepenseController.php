<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RejeterDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function __invoke(Request $request, Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être rejetées.']);
        }

        $validated = $request->validate([
            'motif_rejet' => ['required', 'string', 'in:Non conforme,Autre'],
            'commentaire_rejet' => ['required_if:motif_rejet,Autre', 'nullable', 'string', 'min:5', 'max:255'],
        ], [
            'motif_rejet.required' => 'Le motif de rejet est obligatoire.',
            'motif_rejet.in' => 'Le motif sélectionné est invalide.',
            'commentaire_rejet.required_if' => 'Le commentaire est obligatoire pour le motif "Autre".',
            'commentaire_rejet.min' => 'Le commentaire doit faire au moins 5 caractères.',
        ]);

        $depense->update([
            'statut' => StatutDepense::REJETE,
            'validateur_id' => auth()->id(),
            'date_validation' => now(),
            'motif_rejet' => $validated['motif_rejet'],
            'commentaire_rejet' => $validated['motif_rejet'] === 'Autre' ? ($validated['commentaire_rejet'] ?? null) : null,
        ]);

        $this->audit->record($depense, AuditEvent::REJECTED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'motif_rejet' => $validated['motif_rejet'],
            'description' => "Dépense rejetée — motif : {$validated['motif_rejet']}",
        ]);

        return back()->with('success', 'Dépense rejetée.');
    }
}
