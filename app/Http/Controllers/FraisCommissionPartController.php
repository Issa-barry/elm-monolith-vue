<?php

namespace App\Http\Controllers;

use App\Models\CommandeVente;
use App\Models\CommissionPart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FraisCommissionPartController extends Controller
{
    /**
     * PATCH /commissions/parts/{part}/frais
     *
     * Met à jour les frais d'une part livreur.
     * Recalcule : net = max(0, brut - frais)
     * Propage : statut part → statut commission.
     */
    public function update(Request $request, CommissionPart $part): RedirectResponse
    {
        $this->authorize('viewAny', CommandeVente::class);

        $part->load('commission');

        abort_unless(
            $part->commission?->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $data = $request->validate([
            'frais' => ['required', 'numeric', 'min:0'],
            'type_frais' => ['required', 'string', 'in:carburant,reparation,autre'],
            'commentaire_frais' => ['nullable', 'string', 'max:500'],
        ], [
            'frais.required' => 'Le montant des dépenses est obligatoire.',
            'frais.min' => 'Les dépenses ne peuvent pas être négatives.',
            'type_frais.required' => 'Le type de dépense est obligatoire.',
        ]);

        // Applique frais → recalcule montant_net = max(0, brut - frais)
        $part->appliquerFrais(
            (float) $data['frais'],
            $data['type_frais'] ?? null,
            $data['commentaire_frais'] ?? null,
        );

        // Recalcule montant_verse + statut depuis les paiements réels (peut avoir changé)
        $part->recalculStatut();

        return back()->with('success', 'Dépense mise à jour.');
    }
}
