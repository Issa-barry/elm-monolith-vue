<?php

namespace App\Http\Controllers\Ventes\Regularisation;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ventes\Precommandes\RemboursementPrecommandeController;
use App\Models\CommandeVente;
use App\Models\RemboursementVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Remboursement du trop-perçu laissé par une régularisation « montant reçu » (ADR 0043) — même
 * opération que pour une précommande (décaissement réel depuis un support, ADR 0009), distincte de
 * la régularisation et soumise à sa propre permission `ventes.rembourser`.
 */
class RemboursementRegularisationVenteController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, PrecommandeService $service): RedirectResponse
    {
        $this->authorize('rembourser', $commande_vente);

        $data = $request->validate(RemboursementPrecommandeController::regles(), RemboursementPrecommandeController::messages());

        try {
            $service->rembourser($commande_vente, $request->user(), $data, RemboursementVente::MOTIF_TROP_PERCU);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Remboursement non enregistré : {$e->getMessage()}"]);
        }

        return back()->with('success', 'Remboursement enregistré.');
    }
}
