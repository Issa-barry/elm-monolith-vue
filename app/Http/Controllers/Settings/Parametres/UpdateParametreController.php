<?php

namespace App\Http\Controllers\Settings\Parametres;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateParametreController extends Controller
{
    public function __invoke(Request $request, Parametre $parametre): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);
        abort_if($parametre->organization_id !== auth()->user()->organization_id, 403);
        // Le typage générique (string|max:1000 pour TYPE_STRING) n'impose aucune
        // liste autorisée : passer par ici accepterait n'importe quelle valeur
        // pour theme_preset/theme_primary/theme_surface et contournerait
        // ThemePolicyService. Seul ThemeController::update() sait valider ce
        // groupe correctement.
        abort_if($parametre->groupe === Parametre::GROUPE_THEME, 404);

        $rules = match ($parametre->type) {
            Parametre::TYPE_INTEGER => ['valeur' => 'required|integer|min:0|max:9999999'],
            Parametre::TYPE_DECIMAL => ['valeur' => 'required|numeric|min:0|max:100|decimal:0,2'],
            Parametre::TYPE_BOOLEAN => ['valeur' => 'required|boolean'],
            Parametre::TYPE_JSON => ['valeur' => 'required|json'],
            default => ['valeur' => 'required|string|max:1000'],
        };

        $validated = $request->validate($rules);

        $parametre->update(['valeur' => (string) $validated['valeur']]);

        Parametre::clearCache(auth()->user()->organization_id);

        return back()->with('success', 'Paramètre mis à jour.');
    }
}
