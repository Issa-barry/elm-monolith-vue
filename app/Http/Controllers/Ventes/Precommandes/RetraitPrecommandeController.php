<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Retrait d'une précommande préparée (ADR 0019) : le client repart avec la marchandise. */
class RetraitPrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('validerRetrait', $commande_vente);

        $data = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'required|string',
            'lignes.*.quantite' => 'required|integer|min:0',
        ]);

        try {
            $this->precommandes->validerRetrait($commande_vente, PreparationPrecommandeController::quantitesParLigne($data['lignes']));
        } catch (\RuntimeException $e) {
            // Comptabilisation bloquante (imputation des acomptes) en échec : rien n'est enregistré.
            return back()->withErrors(['comptabilisation' => "Retrait non enregistré : {$e->getMessage()}"]);
        }

        $facture = $commande_vente->fresh()->facture;

        return back()->with('success', match (true) {
            $facture?->tropPercu() > 0 => 'Retrait enregistré. Le client a trop payé : remboursez le trop-perçu.',
            $facture?->isPayee() => 'Retrait enregistré : la précommande est soldée.',
            default => 'Retrait enregistré. Le reste à payer peut être encaissé.',
        });
    }
}
