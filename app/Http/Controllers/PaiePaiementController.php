<?php

namespace App\Http\Controllers;

use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Services\PaieCalculService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaiePaiementController extends Controller
{
    public function store(Request $request, PaieLigne $ligne, PaieCalculService $service): RedirectResponse
    {
        $this->authorize('pay', $ligne->periode);

        $data = $request->validate([
            'montant'       => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date'],
            'mode_paiement' => ['required', 'string', 'in:especes,virement,cheque,mobile_money'],
            'note'          => ['nullable', 'string', 'max:500'],
        ]);

        $maxPaiement = (float) $ligne->reste_a_payer;
        if ((float) $data['montant'] > $maxPaiement + 0.01) {
            return back()->withErrors(['montant' => "Le montant dépasse le reste à payer ({$maxPaiement})."]);
        }

        $ligne->paiements()->create($data);
        $service->recalculerApresPaiement($ligne);

        return back()->with('success', 'Paiement enregistré.');
    }

    public function destroy(PaiePaiement $paiement, PaieCalculService $service): RedirectResponse
    {
        $ligne = $paiement->ligne;
        $this->authorize('pay', $ligne->periode);

        $paiement->delete();
        $service->recalculerApresPaiement($ligne);

        return back()->with('success', 'Paiement supprimé.');
    }
}
