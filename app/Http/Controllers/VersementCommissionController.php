<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CommissionVente;
use App\Models\VersementCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VersementCommissionController extends Controller
{
    public function store(Request $request, CommissionVente $commission_vente): RedirectResponse
    {
        abort_if($commission_vente->isAnnulee(), 422, 'Cette commission est annulée.');
        abort_if($commission_vente->isVersee(), 422, 'Cette commission est déjà entièrement versée.');
        abort_unless(
            $commission_vente->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $restantL = $commission_vente->montant_restant_livreur;
        $restantP = $commission_vente->montant_restant_proprietaire;

        $data = $request->validate([
            'montant_livreur' => ['nullable', 'numeric', 'min:0', "max:{$restantL}"],
            'montant_proprietaire' => ['nullable', 'numeric', 'min:0', "max:{$restantP}"],
            'date_versement' => 'required|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant_livreur.max' => 'Le montant livreur dépasse le restant dû.',
            'montant_proprietaire.max' => 'Le montant propriétaire dépasse le restant dû.',
            'date_versement.required' => 'La date est obligatoire.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
        ]);

        $montantLivreur = (float) ($data['montant_livreur'] ?? 0);
        $montantProprietaire = (float) ($data['montant_proprietaire'] ?? 0);

        if ($montantLivreur <= 0 && $montantProprietaire <= 0) {
            throw ValidationException::withMessages([
                'montant_livreur' => 'Saisissez un montant pour le livreur ou le propriétaire.',
            ]);
        }

        $base = [
            'date_versement' => $data['date_versement'],
            'mode_paiement' => $data['mode_paiement'],
            'note' => $data['note'] ?? null,
        ];

        if ($montantLivreur > 0) {
            $commission_vente->versements()->create([
                ...$base,
                'montant' => $montantLivreur,
                'beneficiaire' => 'livreur',
            ]);
        }

        if ($montantProprietaire > 0) {
            $commission_vente->versements()->create([
                ...$base,
                'montant' => $montantProprietaire,
                'beneficiaire' => 'proprietaire',
            ]);
        }

        return redirect()->back()->with('success', 'Versement enregistré.');
    }

    public function destroy(VersementCommission $versement_commission): RedirectResponse
    {
        abort_unless(
            $versement_commission->commission->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $versement_commission->delete();

        return redirect()->back()->with('success', 'Versement supprimé.');
    }
}
