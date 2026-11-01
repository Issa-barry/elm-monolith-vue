<?php

namespace App\Http\Controllers\Rh;

use App\Enums\StatutSaisieRemuneration;
use App\Http\Controllers\Controller;
use App\Models\AffectationRemuneration;
use App\Models\SaisieRemuneration;
use App\Services\Rh\AffectationRemunerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Montants des éléments « saisis chaque mois » (docs/primes-avantages.md). Règles et droits :
 * AffectationRemunerationService.
 */
class SaisieRemunerationController extends Controller
{
    public function __construct(private readonly AffectationRemunerationService $service) {}

    public function store(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('view', $affectation);
        $this->authorize('create', AffectationRemuneration::class);

        $data = $request->validate([
            'annee' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mois' => ['required', 'integer', 'min:1', 'max:12'],
            'montant' => ['nullable', 'numeric', 'gte:0', 'max:9999999999999'],
            'taux' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'valider' => ['boolean'],
        ], [
            'annee.required' => 'Le mois est obligatoire.',
            'mois.required' => 'Le mois est obligatoire.',
            'montant.gte' => 'Le montant ne peut pas être négatif.',
            'taux.max' => 'Le taux ne peut pas dépasser 100 %.',
        ]);

        $saisie = $this->service->enregistrerSaisie($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $saisie->statut === StatutSaisieRemuneration::VALIDEE
            ? "Montant de {$saisie->periodeLabel()} enregistré et validé."
            : "Montant de {$saisie->periodeLabel()} enregistré : il attend sa validation.");
    }

    public function valider(Request $request, SaisieRemuneration $saisie): RedirectResponse
    {
        $this->authorize('valider', $saisie->affectation);
        $this->service->validerSaisie($saisie, $request->user());

        return back()->with('success', "Montant de {$saisie->periodeLabel()} validé.");
    }

    public function destroy(Request $request, SaisieRemuneration $saisie): RedirectResponse
    {
        $this->authorize('update', $saisie->affectation);
        $this->service->supprimerSaisie($saisie, $request->user());

        return back()->with('success', "Montant de {$saisie->periodeLabel()} supprimé.");
    }
}
