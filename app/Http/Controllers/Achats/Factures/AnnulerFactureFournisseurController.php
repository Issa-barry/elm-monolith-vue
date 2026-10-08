<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnulerFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, FactureFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('annuler', $facture);
        $perimetre->autoriserFacture($facture, $request->user());

        $data = $request->validate(
            ['motif_annulation' => ['required', 'string', 'max:2000']],
            ['motif_annulation.required' => "Le motif d'annulation est obligatoire."],
        );

        $service->annuler($facture, $request->user(), $data['motif_annulation']);

        return back()->with('success', 'Facture annulée.');
    }
}
