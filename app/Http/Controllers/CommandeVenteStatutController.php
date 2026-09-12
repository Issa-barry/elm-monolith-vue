<?php

namespace App\Http\Controllers;

use App\Enums\StatutCommandeVente;
use App\Jobs\NotifierChargementValideCommandeVenteJob;
use App\Models\CommandeVente;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gère les transitions de statut d'une commande vente.
 *
 * POST   /ventes/{commande}/statut/avancer  → avancer()
 * POST   /ventes/{commande}/statut/annuler  → annuler()
 */
class CommandeVenteStatutController extends Controller
{
    /**
     * Avancer d'une étape dans le workflow :
     *   BROUILLON → A_CHARGER → CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS
     * Pour les commandes à réception explicite uniquement (cf.
     * CommandeVente::requiertReceptionExplicite() : distribution_client, Grossiste + Livraison),
     * une étape supplémentaire est possible depuis LIVRAISON_EN_COURS : la validation de
     * réception (→ LIVREE), cf. CommandeVenteService::validerReception().
     */
    public function avancer(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('avancerStatut', $commande_vente);

        $request->validate([
            'lignes' => ['sometimes', 'array'],
            'lignes.*.id' => ['required_with:lignes', 'string'],
            'lignes.*.quantite_chargee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lignes.*.type_ecart' => ['sometimes', 'nullable', 'string'],
            'lignes.*.commentaire_ecart' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Validation de réception (commandes à réception explicite uniquement, cf.
            // CommandeVenteService::validerReception()).
            'lignes.*.quantite_livree' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lignes.*.type_ecart_reception' => ['sometimes', 'nullable', 'string'],
            'lignes.*.commentaire_ecart_reception' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $ancienStatut = $commande_vente->statut;

        try {
            CommandeVenteService::avancerStatut($commande_vente, $request->input('lignes', []));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $action = match ($ancienStatut) {
            StatutCommandeVente::BROUILLON => 'confirmee',
            StatutCommandeVente::A_CHARGER => 'chargement_demarre',
            StatutCommandeVente::CHARGEMENT_EN_COURS => 'chargement_valide',
            StatutCommandeVente::LIVRAISON_EN_COURS => 'reception_validee',
            default => 'statut_change',
        };

        CommandeVenteActiviteService::log($commande_vente, $action);

        // Notifications transactionnelles SMS/WhatsApp (cf. rapport
        // notifications de commande, 07/09/2026) — même point métier exact
        // que l'activité `chargement_valide` ci-dessus, jamais un déclencheur
        // séparé. Le job décide lui-même s'il y a réellement un livreur/client
        // à notifier et si une règle est active — ne bloque jamais cette
        // réponse (queued).
        if ($action === 'chargement_valide') {
            NotifierChargementValideCommandeVenteJob::dispatch($commande_vente->id, $commande_vente->reference);
        }

        return redirect()->route('ventes.show', $commande_vente)
            ->with('success', 'Statut mis à jour.');
    }

    /**
     * Annuler la commande — uniquement depuis BROUILLON ou A_CHARGER.
     */
    public function annuler(Request $request, CommandeVente $commande_vente): RedirectResponse
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
