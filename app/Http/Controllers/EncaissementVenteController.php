<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Features\ModuleFeature;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Services\CashbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class EncaissementVenteController extends Controller
{
    public function store(Request $request, FactureVente $facture_vente): RedirectResponse
    {
        abort_if($facture_vente->isAnnulee(), 422, 'Cette facture est annulée.');
        abort_unless(
            $facture_vente->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $montantRestant = $facture_vente->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            'date_encaissement' => 'required|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => 'Le montant ne peut pas dépasser le restant dû.',
            'date_encaissement.required' => 'La date d\'encaissement est obligatoire.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
        ]);

        $facture_vente->encaissements()->create([
            'montant' => $data['montant'],
            'date_encaissement' => $data['date_encaissement'],
            'mode_paiement' => $data['mode_paiement'],
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $etaitPayee = $facture_vente->isPayee();
        $facture_vente->recalculStatut();
        $estPayeeMaintenent = $facture_vente->isPayee();

        // Auto-clôture si facture payée et commissions soldées
        $facture_vente->commande?->cloturerSiComplete();

        // Cashback : déclenché uniquement quand la facture passe à l'état "payée"
        if (! $etaitPayee && $estPayeeMaintenent) {
            $commande = $facture_vente->commande;
            if ($commande && $commande->client_id && $commande->organization_id) {
                $org = Organization::find($commande->organization_id);
                if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
                    app(CashbackService::class)->processVente($commande);
                }
            }
        }

        return redirect()->back()->with('success', 'Encaissement enregistré.');
    }

    public function destroy(EncaissementVente $encaissement_vente): RedirectResponse
    {
        $facture = $encaissement_vente->facture;

        abort_unless(
            $facture && $facture->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );
        abort_if($facture->isAnnulee(), 422, 'Impossible de modifier une facture annulée.');

        $commandeId = $facture->commande_vente_id;

        $encaissement_vente->delete();

        $facture->recalculStatut();

        return redirect()->back()->with('success', 'Encaissement supprimé.');
    }
}
