<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('create', FactureFournisseur::class);

        $data = $request->validate(
            ['commande_achat_id' => ['required', 'string']] + FactureFournisseurService::reglesSaisie(),
            FactureFournisseurService::messagesSaisie(),
        );

        $commande = CommandeAchat::where('organization_id', $request->user()->organization_id)
            ->findOrFail($data['commande_achat_id']);
        $perimetre->autoriser($commande, $request->user());

        $facture = $service->creer($commande, $request->user(), $data);

        return redirect()->route('achats.factures.show', $facture)
            ->with('success', 'Facture enregistrée en brouillon. Elle doit être validée par une autre personne.');
    }
}
