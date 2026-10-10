<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Support\Achats\FactureFournisseurPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, PerimetreCommandesAchat $perimetre, FactureFournisseurPresenter $presenter): Response|RedirectResponse
    {
        $this->authorize('update', $facture);
        $perimetre->autoriserFacture($facture, $request->user());

        if (! $facture->isBrouillon()) {
            return redirect()->route('achats.factures.show', $facture)
                ->with('error', 'Seule une facture en brouillon peut être modifiée.');
        }

        return Inertia::render('Achats/Factures/Form', [
            'facture' => $presenter->facture($facture),
            'commande' => $presenter->commande($facture->commande),
            'receptions' => $presenter->receptions($facture->commande, $facture->id),
        ]);
    }
}
