<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Features\ModuleFeature;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Services\CashbackService;
use App\Services\CommissionGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class EncaissementVenteController extends Controller
{
    public function store(Request $request, FactureVente $facture_vente): RedirectResponse
    {
        abort_if($facture_vente->isAnnulee(), 422, 'Cette facture est annulee.');
        abort_unless(
            $facture_vente->organization_id === auth()->user()->organization_id,
            403,
            'Acces refuse.'
        );

        $montantRestant = $facture_vente->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            'date_encaissement' => 'required|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit etre superieur a 0.',
            'montant.max' => 'Le montant ne peut pas depasser le restant du.',
            'date_encaissement.required' => "La date d'encaissement est obligatoire.",
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
        $estPayeeMaintenant = $facture_vente->isPayee();

        // Auto cloture si facture payee et commissions soldees.
        $facture_vente->commande?->cloturerSiComplete();

        // Hooks de transition vers facture payee.
        if (! $etaitPayee && $estPayeeMaintenant) {
            $commande = $facture_vente->commande;

            if ($commande && $commande->organization_id) {
                $modeCommission = Parametre::getVentesCommissionMode($commande->organization_id);

                if ($modeCommission === Parametre::COMMISSION_MODE_FACTURE_PAYEE) {
                    $commande->loadMissing('vehicule');

                    if ($commande->vehicule_id && $commande->vehicule) {
                        CommissionGenerator::generateForCommandeIfMissing(
                            $commande,
                            null,
                            Parametre::COMMISSION_MODE_FACTURE_PAYEE
                        );
                    }
                }

                // Cashback: declenche uniquement quand la facture passe a "payee".
                if ($commande->client_id) {
                    $org = Organization::find($commande->organization_id);
                    if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
                        app(CashbackService::class)->processVente($commande);
                    }
                }
            }
        }

        return redirect()->back()->with('success', 'Encaissement enregistre.');
    }

    public function destroy(EncaissementVente $encaissement_vente): RedirectResponse
    {
        $facture = $encaissement_vente->facture;

        abort_unless(
            $facture && $facture->organization_id === auth()->user()->organization_id,
            403,
            'Acces refuse.'
        );
        abort_if($facture->isAnnulee(), 422, 'Impossible de modifier une facture annulee.');

        $encaissement_vente->delete();
        $facture->recalculStatut();

        return redirect()->back()->with('success', 'Encaissement supprime.');
    }
}
