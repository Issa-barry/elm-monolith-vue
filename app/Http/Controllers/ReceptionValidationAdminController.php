<?php

namespace App\Http\Controllers;

use App\Enums\StatutTransfert;
use App\Models\CommissionEnveloppe;
use App\Models\TransfertLogistique;
use App\Services\CommissionTriggerService;
use App\Services\MouvementStockService;
use App\Services\TransfertActiviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReceptionValidationAdminController extends Controller
{
    /**
     * POST /logistique/{transfert}/validation-reception
     *
     * Décision admin sur une réception saisie :
     *  - accord    → commission auto-générée, idempotent
     *  - refus     → décision enregistrée (soft, sans revert)
     *  - invalider → revert statut RECEPTION → TRANSIT pour permettre une nouvelle saisie
     */
    public function store(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('validerReceptionAdmin', $transfert_logistique);

        $data = $request->validate([
            'decision' => ['required', 'in:accord,refus,invalider'],
            'motif' => ['required_if:decision,refus', 'nullable', 'string', 'max:1000'],
        ], [
            'decision.required' => 'La décision est obligatoire.',
            'decision.in' => 'Décision invalide.',
            'motif.required_if' => 'Le motif de refus est obligatoire.',
        ]);

        if ($data['decision'] === 'accord') {
            $transfert_logistique->update([
                'validation_reception' => 'accord',
                'validated_by' => auth()->id(),
                'validated_at' => now(),
                'validation_motif' => null,
            ]);

            // Déclencheur configurable (cf. CommissionTriggerService) : ne génère que si le
            // paramètre organisation est RECEPTION_EFFECTUEE (défaut, comportement historique) —
            // sous CHARGEMENT_VALIDE, la commission existe déjà depuis le départ du transfert
            // (aucune régénération, cf. idempotence de CommissionEnveloppeGenerator). Montant
            // toujours résolu par CommissionRegle (Paramètres > Commissions > Transferts
            // logistiques), plus aucune saisie manuelle par transfert depuis le 03/09/2026.
            CommissionTriggerService::onTransfertReceptionEffectuee($transfert_logistique);

            $enveloppe = CommissionEnveloppe::where('source_type', TransfertLogistique::class)
                ->where('source_id', $transfert_logistique->id)
                ->first();

            if ($enveloppe) {
                TransfertActiviteService::log($transfert_logistique, 'validation_admin_accord', [
                    'commission_enveloppe_id' => $enveloppe->id,
                    'montant_total' => $enveloppe->montant_total,
                ]);
            } else {
                TransfertActiviteService::log($transfert_logistique, 'validation_admin_accord');
            }

            return redirect()->route('logistique.show', $transfert_logistique)
                ->with('success', 'Réception approuvée. Commission générée automatiquement.');
        }

        if ($data['decision'] === 'refus') {
            $transfert_logistique->update([
                'validation_reception' => 'refus',
                'validated_by' => auth()->id(),
                'validated_at' => now(),
                'validation_motif' => $data['motif'] ?? null,
            ]);

            TransfertActiviteService::log($transfert_logistique, 'validation_admin_refus', [
                'motif' => $data['motif'] ?? null,
            ]);

            return redirect()->route('logistique.show', $transfert_logistique)
                ->with('info', 'Réception refusée.');
        }

        // decision = invalider : remettre en TRANSIT pour permettre une nouvelle réception
        MouvementStockService::supprimerEntreeDestination($transfert_logistique);

        $transfert_logistique->update([
            'statut' => StatutTransfert::TRANSIT,
            'date_arrivee_reelle' => null,
            'validation_reception' => null,
            'validated_by' => null,
            'validated_at' => null,
            'validation_motif' => null,
        ]);

        TransfertActiviteService::log($transfert_logistique, 'reception_invalidee');

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('info', 'Réception renvoyée. Le transfert est de nouveau en livraison.');
    }
}
