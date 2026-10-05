<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Préparation d'une précommande (ADR 0019) : lancer (Réservée → À préparer) puis valider avec la
 * quantité préparée par ligne (→ Préparée en retrait, → À charger en livraison).
 */
class PreparationPrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function lancer(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('preparer', $commande_vente);

        $this->precommandes->lancerPreparation($commande_vente);

        return back()->with('success', 'Préparation lancée.');
    }

    public function valider(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('preparer', $commande_vente);

        $data = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'required|string',
            'lignes.*.quantite' => 'required|integer|min:0',
        ]);

        $this->precommandes->validerPreparation($commande_vente, self::quantitesParLigne($data['lignes']));

        return back()->with('success', $commande_vente->fresh()->vehicule_id
            ? 'Préparation validée : la précommande est à charger.'
            : 'Préparation validée : la précommande attend le client.');
    }

    /**
     * @param  array<int, array{id: string, quantite: int}>  $lignes
     * @return array<string, int>
     */
    public static function quantitesParLigne(array $lignes): array
    {
        return collect($lignes)->mapWithKeys(fn (array $l) => [$l['id'] => (int) $l['quantite']])->all();
    }
}
