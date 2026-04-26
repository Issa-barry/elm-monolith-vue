<?php

namespace App\Http\Controllers;

use App\Models\TransfertLogistique;
use App\Services\CommissionLogistiqueService;
use App\Services\TransfertActiviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReceptionValidationAdminController extends Controller
{
    /**
     * POST /logistique/{transfert}/validation-reception
     *
     * Décision admin sur une réception saisie :
     *  - accord → commission auto-générée (200 FG × packs reçus), idempotent
     *  - refus  → aucune commission, motif obligatoire
     */
    public function store(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('validerReceptionAdmin', $transfert_logistique);

        $data = $request->validate([
            'decision' => ['required', 'in:accord,refus'],
            'motif' => ['nullable', 'string', 'max:1000', 'required_if:decision,refus'],
        ], [
            'decision.required' => 'La décision est obligatoire.',
            'decision.in' => 'La décision doit être "accord" ou "refus".',
            'motif.required_if' => 'Le motif est obligatoire en cas de refus.',
        ]);

        $isAccord = $data['decision'] === 'accord';

        $transfert_logistique->update([
            'validation_reception' => $data['decision'],
            'validated_by' => auth()->id(),
            'validated_at' => now(),
            'validation_motif' => $isAccord ? null : ($data['motif'] ?? null),
        ]);

        if ($isAccord) {
            try {
                $commission = CommissionLogistiqueService::genererAutomatique($transfert_logistique);
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

        TransfertActiviteService::log($transfert_logistique, 'validation_admin_refus', [
            'motif' => $data['motif'] ?? null,
        ]);

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('info', 'Réception refusée. Aucune commission générée.');
    }
}
