<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CommissionVente;
use App\Models\VersementCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VersementCommissionController extends Controller
{
    public function store(Request $request, CommissionVente $commission_vente): RedirectResponse
    {
        abort_if($commission_vente->isAnnulee(), 422, 'Cette commission est annulée.');
        abort_if($commission_vente->isVersee(), 422, 'Cette commission est déjà entièrement versée.');
        abort_unless(
            $commission_vente->organization_id === auth()->user()->organization_id,
            403, 'Accès refusé.'
        );

        $restant = $commission_vente->montant_restant;

        $data = $request->validate([
            'montant'        => ['required', 'numeric', 'min:0.01', "max:{$restant}"],
            'date_versement' => 'required|date',
            'mode_paiement'  => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note'           => 'nullable|string|max:2000',
        ], [
            'montant.required'        => 'Le montant est obligatoire.',
            'montant.min'             => 'Le montant doit être supérieur à 0.',
            'montant.max'             => 'Le montant ne peut pas dépasser le restant dû.',
            'date_versement.required' => 'La date est obligatoire.',
            'mode_paiement.required'  => 'Le mode de paiement est obligatoire.',
        ]);

        $commission_vente->versements()->create($data);

        return redirect()->back()->with('success', 'Versement enregistré.');
    }

    public function destroy(VersementCommission $versement_commission): RedirectResponse
    {
        abort_unless(
            $versement_commission->commission->organization_id === auth()->user()->organization_id,
            403, 'Accès refusé.'
        );

        $versement_commission->delete();

        return redirect()->back()->with('success', 'Versement supprimé.');
    }
}
