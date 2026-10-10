<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ValiderFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, FactureFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('valider', $facture);
        $perimetre->autoriserFacture($facture, $request->user());

        $facture = $service->valider($facture, $request->user());

        $message = 'Facture validée : la dette fournisseur est constatée.';

        return $facture->comptabilisation_erreur === null
            ? back()->with('success', $message)
            : back()->with('success', $message)->with('warning', "L'écriture comptable n'a pas pu être passée : {$facture->comptabilisation_erreur}");
    }
}
