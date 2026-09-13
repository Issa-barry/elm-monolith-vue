<?php

namespace App\Http\Controllers\Settings\Parametres;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Inertia\Inertia;
use Inertia\Response;

class EditParametreController extends Controller
{
    public function __invoke(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $orgId = auth()->user()->organization_id;

        $parametres = Parametre::where('organization_id', $orgId)
            ->where('groupe', '!=', Parametre::GROUPE_VENTES)
            // Le thème global a son propre écran (ThemeController) : la valeur
            // n'y est validée que contre la politique de l'environnement
            // (ThemePolicyService), une règle métier que ce formulaire générique
            // ne connaît pas — cf. UpdateParametreController pour le même
            // garde-fou côté écriture.
            ->where('groupe', '!=', Parametre::GROUPE_THEME)
            ->orderBy('groupe')
            ->orderBy('cle')
            ->get()
            ->map(fn (Parametre $p) => [
                'id' => $p->id,
                'cle' => $p->cle,
                'valeur' => $p->valeur,
                'valeur_cast' => Parametre::castValue($p->valeur, $p->type),
                'type' => $p->type,
                'groupe' => $p->groupe,
                'description' => $p->description,
            ]);

        return Inertia::render('settings/Parametres', [
            'parametres' => $parametres,
        ]);
    }
}
