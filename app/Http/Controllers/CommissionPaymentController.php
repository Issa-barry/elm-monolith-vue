<?php

namespace App\Http\Controllers;

use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionPaymentController extends Controller
{
    private const MODES_PAIEMENT = ['especes', 'virement', 'cheque', 'mobile_money'];

    /**
     * POST /logistique/commissions/livreurs/{livreurId}/paiements
     * Paiement global multi-transferts pour un livreur.
     */
    public function storeLivreur(Request $request, string $livreurId): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1'],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
        ]);

        try {
            CommissionPaymentService::payerLivreur(
                livreurId: $livreurId,
                orgId: $orgId,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement enregistré avec succès.');
    }

    /**
     * POST /logistique/commissions/vehicules/{vehicule}/paiements
     */
    public function store(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        abort_unless(
            $vehicule->organization_id === auth()->user()->organization_id,
            403, 'Accès refusé.'
        );

        $data = $request->validate([
            'beneficiary_type' => ['required', Rule::in(['livreur', 'proprietaire'])],
            'beneficiary_id' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:1'],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'beneficiary_type.required' => 'Le type de bénéficiaire est obligatoire.',
            'beneficiary_type.in' => 'Type de bénéficiaire invalide.',
            'beneficiary_id.required' => 'Le bénéficiaire est obligatoire.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
        ]);

        try {
            CommissionPaymentService::payer(
                vehicule: $vehicule,
                beneficiaryType: $data['beneficiary_type'],
                beneficiaryId: $data['beneficiary_id'],
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement enregistré avec succès.');
    }
}
