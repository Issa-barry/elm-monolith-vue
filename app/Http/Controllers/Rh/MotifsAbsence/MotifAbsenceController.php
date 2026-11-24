<?php

namespace App\Http\Controllers\Rh\MotifsAbsence;

use App\Http\Controllers\Controller;
use App\Models\MotifAbsence;
use App\Services\Rh\MotifAbsenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → RH → Motifs d'absence (permission `rh-referentiels.gerer`, comme les fonctions et
 * services RH). Pas de suppression : un motif se désactive, l'historique des absences est conservé.
 */
class MotifAbsenceController extends Controller
{
    public function __construct(private readonly MotifAbsenceService $motifs) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);

        $motifs = MotifAbsence::where('organization_id', $request->user()->organization_id)
            ->withCount('absences')
            ->ordonnes()
            ->get()
            ->map(fn (MotifAbsence $m) => [
                'id' => $m->id,
                'libelle' => $m->libelle,
                'is_active' => $m->is_active,
                'absences_count' => $m->absences_count,
            ]);

        return Inertia::render('MotifsAbsence/Index', ['motifs' => $motifs]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $this->motifs->creer($this->libelle($request), $orgId);

        return back()->with('success', 'Motif créé.');
    }

    public function update(Request $request, MotifAbsence $motifAbsence): RedirectResponse
    {
        $this->autoriser($request, $motifAbsence);

        $this->motifs->renommer($motifAbsence, $this->libelle($request));

        return back()->with('success', 'Motif mis à jour.');
    }

    public function toggle(Request $request, MotifAbsence $motifAbsence): RedirectResponse
    {
        $this->autoriser($request, $motifAbsence);

        $this->motifs->basculer($motifAbsence);

        return back()->with('success', $motifAbsence->is_active ? 'Motif activé.' : 'Motif désactivé.');
    }

    private function autoriser(Request $request, MotifAbsence $motif): void
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        // Contrôle explicite : le Gate::before du super administrateur ne vérifie pas l'organisation.
        abort_if($motif->organization_id !== $request->user()->organization_id, 403);
    }

    private function libelle(Request $request): string
    {
        return $request->validate(
            ['libelle' => ['required', 'string', 'max:100']],
            ['libelle.required' => 'Le libellé est obligatoire.', 'libelle.max' => 'Le libellé ne doit pas dépasser 100 caractères.'],
        )['libelle'];
    }
}
