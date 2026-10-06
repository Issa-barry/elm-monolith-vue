<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\PrecommandeModeRemiseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changement du mode de remise d'une précommande avant le chargement (ADR 0019, décision D16) :
 * `vehicule_id` renseigné = passer en livraison avec ce véhicule, absent = passer en retrait.
 */
class ModeRemisePrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeModeRemiseService $modeRemise) {}

    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('changerModeRemise', $commande_vente);

        $data = $request->validate([
            'vehicule_id' => ['nullable', 'string'],
        ]);

        $this->modeRemise->changer($commande_vente, $data['vehicule_id'] ?? null);

        return back()->with('success', $commande_vente->fresh()->vehicule_id
            ? 'La précommande sera livrée.'
            : 'La précommande sera retirée par le client.');
    }
}
