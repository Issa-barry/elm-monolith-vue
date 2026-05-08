<?php

namespace App\Http\Controllers;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutTransfert;
use App\Models\TransfertLogistique;
use App\Services\CommissionLogistiqueService;
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
            'montant_par_pack' => ['required_if:decision,accord', 'nullable', 'integer', 'min:1'],
            'motif' => ['required_if:decision,refus', 'nullable', 'string', 'max:1000'],
        ], [
            'decision.required' => 'La décision est obligatoire.',
            'decision.in' => 'Décision invalide.',
            'montant_par_pack.required_if' => 'Le montant par pack est obligatoire.',
            'montant_par_pack.min' => 'Le montant par pack doit être supérieur à 0.',
            'motif.required_if' => 'Le motif de refus est obligatoire.',
        ]);

        if ($data['decision'] === 'accord') {
            $transfert_logistique->update([
                'validation_reception' => 'accord',
                'validated_by' => auth()->id(),
                'validated_at' => now(),
                'validation_motif' => null,
            ]);

            $transfert_logistique->loadMissing('lignes');
            $quantiteRecue = (int) $transfert_logistique->lignes->sum('quantite_recue');
            $montantParPack = (float) $data['montant_par_pack'];

            try {
                $commission = CommissionLogistiqueService::genererPourTransfert(
                    $transfert_logistique,
                    BaseCalculLogistique::PAR_PACK->value,
                    $montantParPack,
                    $quantiteRecue > 0 ? $quantiteRecue : 0,
                );
                TransfertActiviteService::log($transfert_logistique, 'validation_admin_accord', [
                    'commission_id' => $commission->id,
                    'montant_total' => $commission->montant_total,
                    'quantite_packs' => $commission->quantite_reference,
                ]);
            } catch (\InvalidArgumentException $e) {
                TransfertActiviteService::log($transfert_logistique, 'validation_admin_accord');

                return redirect()->route('logistique.show', $transfert_logistique)
                    ->with('warning', 'Réception approuvée. '.$e->getMessage());
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
