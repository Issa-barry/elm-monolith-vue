<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, FactureFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('update', $facture);
        $perimetre->autoriserFacture($facture, $request->user());

        $data = $request->validate(FactureFournisseurService::reglesSaisie(), FactureFournisseurService::messagesSaisie());

        $service->modifier($facture, $request->user(), $data);

        return redirect()->route('achats.factures.show', $facture)->with('success', 'Facture modifiée.');
    }
}
