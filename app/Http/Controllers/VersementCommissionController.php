<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\VersementCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VersementCommissionController extends Controller
{
    /**
     * Enregistre un versement sur une part précise.
     * Route : POST /commissions/{commission}/parts/{part}/versements
     */
    public function store(Request $request, CommissionVente $commission, CommissionPart $part): RedirectResponse
    {
        abort_unless($commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');
        abort_unless($part->commission_vente_id === $commission->id, 403, 'Part invalide.');
        abort_if($commission->isAnnulee(), 422, 'Cette commission est annulée.');
        abort_if($part->isVersee(), 422, 'Cette part est déjà entièrement versée.');

        $restant = $part->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$restant}"],
            'date_versement' => 'required|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => 'Le montant dépasse le restant dû.',
            'date_versement.required' => 'La date est obligatoire.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
        ]);

        $part->versements()->create($data);

        // Recalcul statut de la part puis de la commission
        $part->recalculStatut();

        // Auto-clôture commande si facture payée et commissions toutes versées
        $commission->commande?->cloturerSiComplete();

        return redirect()
            ->route('commissions.show', $commission)
            ->with('success', 'Versement enregistré.');
    }

    /**
     * Supprime un versement.
     * Route : DELETE /versements-commissions/{versement}
     */
    public function destroy(VersementCommission $versement_commission): RedirectResponse
    {
        $commission = $versement_commission->part->commission;
        abort_unless($commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');

        $versement_commission->delete();

        return redirect()
            ->route('commissions.show', $commission)
            ->with('success', 'Versement supprimé.');
    }
}
