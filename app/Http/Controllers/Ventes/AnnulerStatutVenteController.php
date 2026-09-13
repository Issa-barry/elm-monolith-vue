<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnulerStatutVenteController extends Controller
{
    /**
     * Annuler la commande — uniquement depuis BROUILLON ou A_CHARGER.
     */
    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('annuler', $commande_vente);

        $data = $request->validate([
            'motif_annulation_code' => ['required', 'string'],
            'motif_annulation_detail' => ['nullable', 'string', 'max:2000', 'required_if:motif_annulation_code,autre'],
        ], [
            'motif_annulation_code.required' => "Le motif d'annulation est obligatoire.",
            'motif_annulation_detail.required_if' => "Veuillez préciser la raison de l'annulation.",
        ]);

        $motif = $data['motif_annulation_code'];
        if (! empty($data['motif_annulation_detail'])) {
            $motif .= ' : '.$data['motif_annulation_detail'];
        }

        CommandeVenteService::annuler($commande_vente, $motif);
        CommandeVenteActiviteService::log($commande_vente, 'annulee', [
            'motif' => $motif,
        ]);

        return redirect()->route('ventes.show', $commande_vente)
            ->with('success', 'Commande annulée.');
    }
}
