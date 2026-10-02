<?php

namespace App\Http\Controllers\Settings\Logistique;

use App\Enums\DeclencheurCommissionLogistique;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateLogistiqueParametrageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $validated = $request->validate([
            'declencheur_commission_logistique' => ['required', Rule::in(array_column(DeclencheurCommissionLogistique::cases(), 'value'))],
            'approbation_reception_logistique_obligatoire' => ['required', 'boolean'],
        ]);

        $orgId = auth()->user()->organization_id;

        Parametre::setDeclencheurCommissionLogistique(
            $orgId,
            DeclencheurCommissionLogistique::from($validated['declencheur_commission_logistique']),
        );
        Parametre::setApprobationReceptionLogistiqueObligatoire(
            $orgId,
            (bool) $validated['approbation_reception_logistique_obligatoire'],
        );

        Parametre::clearCache($orgId);

        return back()->with('success', 'Parametrage logistique mis a jour.');
    }
}
