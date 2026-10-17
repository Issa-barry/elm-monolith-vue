<?php

namespace App\Http\Controllers;

use App\Enums\StatutTransfert;
use App\Jobs\NotifierChargementValideTransfertJob;
use App\Models\TransfertLogistique;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'lignes.*.id' => ['required_with:lignes', 'string'],
            // Chargement (CHARGEMENT → TRANSIT) : optionnel, présent seulement pour cette étape
            'lignes.*.quantite_chargee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Réception (TRANSIT → RECEPTION) : optionnel, présent seulement pour cette étape
            'lignes.*.quantite_recue' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lignes.*.ecart_type' => ['sometimes', 'nullable', 'string'],
            'lignes.*.ecart_motif' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Mettre à jour les lignes si fournies — chaque saisie seulement à son étape (quantité
        // chargée en CHARGEMENT, réception en TRANSIT) et sous le verrou du transfert : une quantité
        // chargée modifiée après le départ fausserait la sortie de stock déjà passée et les
        // quantités retournables (ADR 0029). Transaction distincte de avancerStatut() : une
        // transition refusée conserve la saisie, comme avant.
        if ($request->has('lignes')) {
            DB::transaction(function () use ($request, $transfert_logistique) {
                $transfert = TransfertLogistique::whereKey($transfert_logistique->id)->lockForUpdate()->firstOrFail();
                $transfert->load('lignes');
                $chargementOuvert = $transfert->statut === StatutTransfert::CHARGEMENT;
                $receptionOuverte = $transfert->statut === StatutTransfert::TRANSIT;

                foreach ($request->input('lignes') as $ligneData) {
                    $ligne = $transfert->lignes->find($ligneData['id']);
                    if (! $ligne) {
                        continue;
                    }

                    $update = [];

                    if ($chargementOuvert && isset($ligneData['quantite_chargee'])) {
                        $update['quantite_chargee'] = $ligneData['quantite_chargee'];
                    }

                    if ($receptionOuverte) {
                        foreach (['quantite_recue', 'ecart_type', 'ecart_motif'] as $champ) {
                            if (isset($ligneData[$champ])) {
                                $update[$champ] = $ligneData[$champ];
                            }
                        }
                    }

                    if (! empty($update)) {
                        $ligne->update($update);
                    }
                }
            });
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

        // Notifications transactionnelles SMS/WhatsApp (cf. rapport
        // notifications de commande, 07/09/2026) — même point métier exact que
        // l'activité `chargement_valide` ci-dessus. Livreur uniquement (aucun
        // client sur un transfert logistique), cf. docblock du job.
        if ($action === 'chargement_valide') {
            NotifierChargementValideTransfertJob::dispatch($transfert_logistique->id, $transfert_logistique->reference);
        }

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Statut mis à jour.');
    }

    /**
     * Annuler le transfert.
     */
    public function annuler(TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('annuler', $transfert_logistique);

        // Gate::before laisse passer le super admin quel que soit le statut : l'état est
        // revérifié ici, et renvoyé comme erreur de formulaire (pas un 422 brut qu'Inertia
        // afficherait en page d'erreur).
        if (! $transfert_logistique->isAnnulable()) {
            return back()->withErrors([
                'statut' => 'L\'annulation n\'est possible qu\'en phase de brouillon ou de chargement : la marchandise est déjà partie.',
            ]);
        }

        TransfertLogistiqueService::annuler($transfert_logistique);
        TransfertActiviteService::log($transfert_logistique, 'annule');

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Transfert annulé.');
    }
}
