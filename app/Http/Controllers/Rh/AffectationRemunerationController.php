<?php

namespace App\Http\Controllers\Rh;

use App\Enums\StatutAffectationRemuneration;
use App\Http\Controllers\Comptabilite\HistoriqueActionsController;
use App\Http\Controllers\Controller;
use App\Http\Requests\AffectationRemunerationRequest;
use App\Models\AffectationRemuneration;
use App\Models\AuditLog;
use App\Services\Rh\AffectationRemunerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Actions sur les primes d'un salarié, depuis sa fiche (docs/primes-avantages.md, ADR 0039, ADR 0052).
 * Chaque écriture passe par AffectationRemunerationService, qui revérifie le droit de validation,
 * l'organisation et l'agence du salarié.
 */
class AffectationRemunerationController extends Controller
{
    public function __construct(private readonly AffectationRemunerationService $service) {}

    public function store(AffectationRemunerationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $affectation = $this->service->affecter($data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Prime enregistrée et validée.'
            : 'Prime enregistrée : elle attend sa validation.');
    }

    public function update(AffectationRemunerationRequest $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $data = $request->validated();
        $affectation = $this->service->modifier($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Proposition modifiée et validée.'
            : 'Proposition modifiée.');
    }

    public function changerMontant(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('view', $affectation);
        $this->authorize('create', AffectationRemuneration::class);

        $data = $request->validate([
            'date_effet' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_effet'],
            'montant' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'taux' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'valider' => ['boolean'],
        ], [
            'date_effet.required' => 'La date d\'effet du nouveau montant est obligatoire.',
            'date_fin.after_or_equal' => 'La fin d\'effet doit être postérieure ou égale à la date du changement.',
            'montant.gt' => 'Le montant doit être supérieur à 0.',
            'taux.max' => 'Le taux ne peut pas dépasser 100 %.',
        ]);

        $successeur = $this->service->changerMontant($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $successeur->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Nouveau montant validé : il s\'applique à partir du '.$successeur->date_debut->format('d/m/Y').'.'
            : 'Changement de montant enregistré : il attend sa validation.');
    }

    public function demanderFin(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('update', $affectation);

        $data = $request->validate([
            'date_fin' => ['required', 'date_format:Y-m-d'],
            'motif' => ['required', 'string', 'max:255'],
            'valider' => ['boolean'],
        ], [
            'date_fin.required' => 'La date de fin d\'effet est obligatoire.',
            'motif.required' => 'Le motif est obligatoire.',
        ]);

        $affectation = $this->service->demanderFin($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->date_fin_demandee === null
            ? 'Fin d\'effet enregistrée au '.$affectation->date_fin->format('d/m/Y').'.'
            : 'Fin d\'effet demandée : elle attend sa validation.');
    }

    public function retirerDemandeFin(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('update', $affectation);
        $this->service->retirerDemandeFin($affectation, $request->user());

        return back()->with('success', 'Demande de fin d\'effet retirée.');
    }

    public function valider(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('valider', $affectation);
        $this->service->valider($affectation, $request->user());

        return back()->with('success', 'Validation enregistrée.');
    }

    public function annuler(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('delete', $affectation);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);
        $this->service->annuler($affectation, $data['motif'] ?? null, $request->user());

        return back()->with('success', 'Prime annulée. Elle reste visible dans l\'historique.');
    }

    public function historique(Request $request, AffectationRemuneration $affectation): JsonResponse
    {
        $this->authorize('view', $affectation);
        // Contrôles explicites : le Gate::before du super administrateur ne vérifie ni l'organisation ni l'agence.
        abort_unless(AffectationRemunerationService::peutConsulter($affectation->employe, $request->user()), 403);

        $logs = AuditLog::where('organization_id', $affectation->organization_id)
            ->where('auditable_type', $affectation->getMorphClass())
            ->where('auditable_id', $affectation->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => HistoriqueActionsController::transformLog($log));

        return response()->json($logs);
    }
}
