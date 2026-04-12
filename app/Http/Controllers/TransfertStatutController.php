<?php

namespace App\Http\Controllers;

use App\Enums\StatutTransfert;
use App\Models\TransfertLogistique;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gère les transitions de statut d'un transfert logistique.
 *
 * POST /logistique/{transfert}/statut          → avancer
 * DELETE /logistique/{transfert}/statut        → annuler
 */
class TransfertStatutController extends Controller
{
    /**
     * Avancer d'une étape.
     */
    public function avancer(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('avancerStatut', $transfert_logistique);

        // Données optionnelles pour les étapes qui nécessitent des saisies
        $request->validate([
            'lignes' => ['sometimes', 'array'],
            'lignes.*.id' => ['required_with:lignes', 'integer'],
            // Chargement (CHARGEMENT → TRANSIT) : optionnel, présent seulement pour cette étape
            'lignes.*.quantite_chargee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Réception (TRANSIT → RECEPTION) : optionnel, présent seulement pour cette étape
            'lignes.*.quantite_recue' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lignes.*.ecart_type' => ['sometimes', 'nullable', 'string'],
            'lignes.*.ecart_motif' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Mettre à jour les lignes si fournies
        if ($request->has('lignes')) {
            $transfert_logistique->loadMissing('lignes');

            foreach ($request->input('lignes') as $ligneData) {
                $ligne = $transfert_logistique->lignes->find($ligneData['id']);
                if (! $ligne) {
                    continue;
                }

                $update = [];

                if (isset($ligneData['quantite_chargee'])) {
                    $update['quantite_chargee'] = $ligneData['quantite_chargee'];
                }

                if (isset($ligneData['quantite_recue'])) {
                    $update['quantite_recue'] = $ligneData['quantite_recue'];
                }

                if (isset($ligneData['ecart_type'])) {
                    $update['ecart_type'] = $ligneData['ecart_type'];
                }

                if (isset($ligneData['ecart_motif'])) {
                    $update['ecart_motif'] = $ligneData['ecart_motif'];
                }

                if (! empty($update)) {
                    $ligne->update($update);
                }
            }
        }

        $ancienStatut = $transfert_logistique->statut;

        try {
            TransfertLogistiqueService::avancerStatut($transfert_logistique);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $action = match ($ancienStatut) {
            StatutTransfert::BROUILLON => 'chargement_demarre',
            StatutTransfert::CHARGEMENT => 'chargement_valide',
            StatutTransfert::TRANSIT => 'reception_validee',
            StatutTransfert::RECEPTION => 'cloture',
            default => 'statut_change',
        };
        TransfertActiviteService::log($transfert_logistique, $action);

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Statut mis à jour.');
    }

    /**
     * Annuler le transfert.
     */
    public function annuler(TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('annuler', $transfert_logistique);

        // Garde API : annulation interdite dès TRANSIT (ne devrait pas passer la policy, mais double sécurité)
        abort_unless(
            in_array($transfert_logistique->statut, [StatutTransfert::BROUILLON, StatutTransfert::CHARGEMENT]),
            422,
            'L\'annulation n\'est possible qu\'en phase de brouillon ou de chargement.'
        );

        TransfertLogistiqueService::annuler($transfert_logistique);
        TransfertActiviteService::log($transfert_logistique, 'annule');

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Transfert annulé.');
    }
}
