<?php

namespace App\Http\Controllers;

use App\Enums\BaseCalculLogistique;
use App\Models\TransfertLogistique;
use App\Services\CommissionLogistiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Génération de la commission logistique d'un transfert clôturé.
 */
class CommissionLogistiqueController extends Controller
{
    /**
     * Générer ou recalculer la commission d'un transfert.
     * POST /logistique/{transfert}/commission
     */
    public function store(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('genererCommission', $transfert_logistique);

        // Lire base_calcul en avance pour la règle conditionnelle sur quantite_reference
        $baseCalcul = $request->input('base_calcul');

        $data = $request->validate([
            'base_calcul'        => ['required', Rule::in(array_column(BaseCalculLogistique::cases(), 'value'))],
            'valeur_base'        => ['required', 'numeric', 'min:0'],
            'quantite_reference' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(in_array($baseCalcul, [
                    BaseCalculLogistique::PAR_PACK->value,
                    BaseCalculLogistique::PAR_KM->value,
                ])),
            ],
        ], [
            'base_calcul.required'        => 'La base de calcul est obligatoire.',
            'base_calcul.in'              => 'Base de calcul invalide.',
            'valeur_base.required'        => 'La valeur de base est obligatoire.',
            'valeur_base.min'             => 'La valeur de base doit être positive.',
            'quantite_reference.required' => 'La quantité de référence est obligatoire pour ce mode de calcul.',
            'quantite_reference.min'      => 'La quantité de référence doit être supérieure à 0.',
        ]);

        try {
            CommissionLogistiqueService::genererPourTransfert(
                $transfert_logistique,
                $data['base_calcul'],
                (float) $data['valeur_base'],
                isset($data['quantite_reference']) ? (int) $data['quantite_reference'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['commission' => $e->getMessage()]);
        }

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Commission générée avec succès.');
    }
}
