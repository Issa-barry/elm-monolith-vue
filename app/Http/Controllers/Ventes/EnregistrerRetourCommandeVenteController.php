<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\MotifRetourCommande;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\CommandeVenteRetourService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnregistrerRetourCommandeVenteController extends Controller
{
    /**
     * Enregistrer un retour de livraison (tout ou partie de la marchandise chargée revenue avec le
     * livreur, avant tout encaissement) — cf. CommandeVenteRetourService. Retour total : la commande
     * passe en « Retournée » et sa facture est annulée.
     */
    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('enregistrerRetour', $commande_vente);

        $data = $request->validate([
            'motif' => ['required', Rule::enum(MotifRetourCommande::class)],
            'commentaire' => ['nullable', 'string', 'max:1000', 'required_if:motif,'.MotifRetourCommande::AUTRE->value],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0'],
        ], [
            'motif.required' => 'Le motif du retour est obligatoire.',
            'motif.enum' => 'Motif de retour invalide.',
            'commentaire.required_if' => 'Précisez la raison du retour.',
            'lignes.required' => 'Indiquez au moins une quantité retournée.',
            'lignes.*.quantite.integer' => 'Les quantités retournées doivent être des nombres entiers.',
        ]);

        try {
            $retour = CommandeVenteRetourService::enregistrer(
                $commande_vente,
                $data['lignes'],
                MotifRetourCommande::from($data['motif']),
                $data['commentaire'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $redirect = redirect()->route('ventes.show', $commande_vente)
            ->with('success', $retour->retour_total
                ? 'Retour total enregistré : la commande est retournée et sa facture annulée.'
                : 'Retour enregistré : la facture a été recalculée et la marchandise remise en stock.');

        // Le retour reste valide ; seule l'écriture comptable est à régulariser (cf.
        // CommandeVenteRetourService::comptabiliserRetour()).
        if ($retour->getAttribute('comptabilisation_echouee')) {
            $redirect->with('warning', 'Le retour est enregistré, mais son écriture comptable a échoué : elle reste à régulariser (voir le journal d\'activité de la commande).');
        }

        return $redirect;
    }
}
