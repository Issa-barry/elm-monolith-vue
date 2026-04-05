<?php

namespace App\Http\Controllers;

use App\Models\CommissionPart;
use App\Models\CommissionVente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Actions métier sur une part de commission (ex: ajustement des frais).
 */
class CommissionPartController extends Controller
{
    /**
     * Met à jour les frais supplémentaires d'une part propriétaire.
     * Route : PATCH /commissions/{commission}/parts/{part}/frais
     */
    public function updateFrais(Request $request, CommissionVente $commission, CommissionPart $part): RedirectResponse
    {
        abort_unless($commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');
        abort_unless($part->commission_vente_id === $commission->id, 403, 'Part invalide.');
        abort_unless($part->type_beneficiaire === 'proprietaire', 422, 'Les frais ne s\'appliquent qu\'à la part propriétaire.');
        abort_if($commission->isAnnulee(), 422, 'Cette commission est annulée.');

        $data = $request->validate([
            'frais_supplementaires' => [
                'required',
                'numeric',
                'min:0',
                "max:{$part->montant_brut}",
            ],
        ], [
            'frais_supplementaires.required' => 'Le montant des frais est obligatoire.',
            'frais_supplementaires.min'      => 'Les frais ne peuvent pas être négatifs.',
            'frais_supplementaires.max'      => 'Les frais ne peuvent pas dépasser la part brute.',
        ]);

        $part->appliquerFrais((float) $data['frais_supplementaires']);

        return redirect()
            ->route('commissions.show', $commission)
            ->with('success', 'Frais mis à jour.');
    }
}
