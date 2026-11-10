<?php

namespace App\Http\Controllers\Settings\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAgencesPayeusesRequest;
use App\Services\Commission\AgencePayeuseCommissionService;
use Illuminate\Http\RedirectResponse;

/**
 * Enregistre la politique de paiement des commissions (ADR 0061). Les fiches non entièrement payées
 * passent aussitôt à leur nouvelle agence payeuse ; les paiements déjà faits ne bougent pas.
 */
class UpdateAgencesPayeusesController extends Controller
{
    public function __invoke(UpdateAgencesPayeusesRequest $request, AgencePayeuseCommissionService $service): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $reaffectees = $service->enregistrer(
            $user->organization_id,
            $user,
            $validated['agence_centralisee_id'] ?? null,
            $validated['regles'],
        );

        $message = match (true) {
            $reaffectees === 0 => 'Agences payeuses enregistrées. Aucune fiche non payée ne change d\'agence.',
            $reaffectees === 1 => 'Agences payeuses enregistrées. 1 fiche non payée change d\'agence qui paie.',
            default => "Agences payeuses enregistrées. {$reaffectees} fiches non payées changent d'agence qui paie.",
        };

        return back()->with('success', $message);
    }
}
