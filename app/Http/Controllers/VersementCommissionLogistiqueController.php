<?php

namespace App\Http\Controllers;

use App\Models\CommissionLogistiquePart;
use App\Services\CommissionLogistiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enregistrement de versements sur une part de commission logistique.
 */
class VersementCommissionLogistiqueController extends Controller
{
    private const MODES_PAIEMENT = ['especes', 'virement', 'cheque', 'mobile_money'];

    /**
     * POST /commissions-logistique/parts/{part}/versements
     */
    public function store(Request $request, CommissionLogistiquePart $part): RedirectResponse
    {
        // Vérification cross-org et autorisation via le transfert parent
        $commission = $part->commission()->with('transfert')->firstOrFail();
        $transfert  = $commission->transfert;

        abort_unless(
            $transfert->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $this->authorize('verserCommission', $transfert);

        abort_if($part->isVersee(), 422, 'Cette part est déjà entièrement versée.');

        $montantMax = (float) $part->montant_restant;

        $data = $request->validate([
            'montant'       => ['required', 'numeric', 'min:1', "max:{$montantMax}"],
            'date_versement'=> ['required', 'date', 'before_or_equal:today'],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note'          => ['nullable', 'string', 'max:500'],
        ], [
            'montant.required'        => 'Le montant est obligatoire.',
            'montant.min'             => 'Le montant doit être supérieur à 0.',
            'montant.max'             => "Le montant ne peut pas dépasser le restant dû ({$montantMax} GNF).",
            'date_versement.required' => 'La date de versement est obligatoire.',
            'date_versement.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'mode_paiement.required'  => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in'        => 'Mode de paiement invalide.',
        ]);

        CommissionLogistiqueService::verser(
            $part,
            (float) $data['montant'],
            $data['date_versement'],
            $data['mode_paiement'],
            $data['note'] ?? null,
        );

        return redirect()->route('logistique.show', $transfert)
            ->with('success', 'Versement enregistré.');
    }
}
