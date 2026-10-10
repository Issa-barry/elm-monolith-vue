<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\PaiementFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorePaiementFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, PaiementFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('payer', $facture);
        $perimetre->autoriserFacture($facture, $request->user());

        $data = $request->validate(PaiementFournisseurService::regles(), PaiementFournisseurService::messages());

        $service->payer($facture, $request->user(), $data);

        return back()->with('success', 'Paiement enregistré.');
    }
}
