<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\RedirectResponse;

/** Confirmation de la livraison d'une précommande (ADR 0019, décision D13) : le chargement ne vaut pas livraison. */
class LivraisonPrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function __invoke(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('confirmerLivraison', $commande_vente);

        $this->precommandes->confirmerLivraison($commande_vente);

        $commande = $commande_vente->fresh('facture');

        return back()->with('success', match (true) {
            $commande->facture?->tropPercu() > 0 => 'Livraison confirmée. Le client a trop payé : remboursez le trop-perçu.',
            $commande->facture?->isPayee() => 'Livraison confirmée : la précommande est soldée.',
            default => 'Livraison confirmée. Le reste à payer peut être encaissé.',
        });
    }
}
