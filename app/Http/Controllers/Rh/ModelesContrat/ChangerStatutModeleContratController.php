<?php

namespace App\Http\Controllers\Rh\ModelesContrat;

use App\Enums\TypeContrat;
use App\Http\Controllers\Controller;
use App\Services\Rh\ModeleContratService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Activer (après relecture) ou repasser en brouillon un modèle de contrat (ADR 0044). Seul un
 * modèle actif permet de générer ; les contrats déjà générés ne changent pas.
 */
class ChangerStatutModeleContratController extends Controller
{
    public function __invoke(Request $request, string $type, string $action, ModeleContratService $modeles): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        $typeContrat = TypeContrat::tryFrom($type) ?? abort(404);
        $orgId = $request->user()->organization_id;

        if ($action === 'activer') {
            $modeles->activer($orgId, $typeContrat, $request->user());

            return back()->with('success', 'Modèle '.$typeContrat->label().' activé : il sert désormais à générer les contrats.');
        }

        $modeles->desactiver($orgId, $typeContrat);

        return back()->with('success', 'Modèle '.$typeContrat->label().' repassé en brouillon : plus aucun contrat ne peut être généré avec.');
    }
}
