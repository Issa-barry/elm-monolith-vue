<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Annulation d'une précommande (ADR 0019, décision D1) : procédure simple avant préparation,
 * renforcée ensuite (permission dédiée + code e-mail si l'organisation l'exige). Les sommes versées
 * sont remboursées dans la même opération. Distincte de l'annulation exceptionnelle (ADR 0004).
 */
class AnnulationPrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function demanderCode(Request $request, CommandeVente $commande_vente): JsonResponse
    {
        $this->authorize('annulerPrecommande', $commande_vente);

        return response()->json($this->precommandes->demanderCodeAnnulation($commande_vente, $request->user()));
    }

    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('annulerPrecommande', $commande_vente);

        $avecRemboursement = (float) $request->input('montant', 0) > 0;
        $data = $request->validate([
            'motif' => 'required|string|max:2000',
            'code' => 'nullable|string|max:12',
            ...($avecRemboursement ? RemboursementPrecommandeController::regles() : []),
        ], RemboursementPrecommandeController::messages());

        try {
            $this->precommandes->annuler(
                $commande_vente,
                $request->user(),
                $data['motif'],
                $avecRemboursement ? $data : [],
                $data['code'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Annulation non enregistrée : {$e->getMessage()}"]);
        }

        return back()->with('success', $avecRemboursement
            ? 'Précommande annulée : le client a été remboursé et le stock libéré.'
            : 'Précommande annulée : le stock est libéré.');
    }
}
