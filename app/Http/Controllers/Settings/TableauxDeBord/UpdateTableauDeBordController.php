<?php

namespace App\Http\Controllers\Settings\TableauxDeBord;

use App\Features\TableauDeBordFeature;
use App\Http\Controllers\Controller;
use App\Services\TableauDeBordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Active ou désactive un tableau de bord pour l'organisation de l'administrateur, et elle seule (ADR 0071). */
class UpdateTableauDeBordController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $validated = $request->validate([
            'tableau' => ['required', 'string', Rule::in(TableauDeBordFeature::ALL)],
            'actif' => ['required', 'boolean'],
        ]);

        $org = auth()->user()->loadMissing('organization')->organization;
        abort_unless($org, 403);

        $actif = (bool) $validated['actif'];
        TableauDeBordService::definir($validated['tableau'], $org, $actif);

        $libelle = TableauDeBordFeature::libelles()[$validated['tableau']];

        return back()->with('success', $actif ? "{$libelle} activé." : "{$libelle} désactivé.");
    }
}
