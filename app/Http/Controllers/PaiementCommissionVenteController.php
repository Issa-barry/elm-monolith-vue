<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Services\CommissionVentePaiementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PaiementCommissionVenteController extends Controller
{
    /**
     * POST /commissions/beneficiaires/{type}/{beneficiaireId}/paiements
     *
     * Enregistre un paiement groupé sur l'ensemble des commissions disponibles
     * d'un bénéficiaire (FIFO par date d'éligibilité).
     *
     * Input : montant, mode_paiement, paid_at, note (optionnel)
     * Contrainte : montant ≤ disponible_maintenant
     */
    public function store(Request $request, string $type, int $beneficiaireId): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422, 'Type bénéficiaire invalide.');

        $data = $request->validate([
            'montant'       => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'paid_at'       => 'required|date',
            'note'          => 'nullable|string|max:2000',
        ], [
            'montant.required'       => 'Le montant est obligatoire.',
            'montant.min'            => 'Le montant doit être supérieur à 0.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'paid_at.required'       => 'La date de paiement est obligatoire.',
        ]);

        try {
            CommissionVentePaiementService::payer(
                organizationId: auth()->user()->organization_id,
                type:           $type,
                beneficiaireId: $beneficiaireId,
                montant:        (float) $data['montant'],
                modePaiement:   $data['mode_paiement'],
                paidAt:         $data['paid_at'],
                note:           $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement groupé enregistré.');
    }
}
