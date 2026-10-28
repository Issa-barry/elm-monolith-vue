<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Http\Controllers\Controller;
use App\Models\TypeRemuneration;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Valider, fermer, désactiver ou réactiver un type (route : {action} limité à ces quatre valeurs).
 * Droit `rh-types-remuneration.valider` exigé, revérifié par le service avec la transition.
 */
class ChangerStatutTypeRemunerationController extends Controller
{
    private const MESSAGES = [
        'valider' => 'Type validé : il peut désormais être attribué.',
        'fermer' => 'Type fermé aux nouvelles attributions.',
        'desactiver' => 'Type désactivé.',
        'reactiver' => 'Type réactivé.',
    ];

    public function __invoke(Request $request, TypeRemuneration $typeRemuneration, string $action, TypeRemunerationService $service): RedirectResponse
    {
        abort_unless(array_key_exists($action, self::MESSAGES), 404);
        $this->authorize('valider', $typeRemuneration);

        match ($action) {
            'valider' => $service->valider($typeRemuneration, $request->user()),
            'fermer' => $service->fermer($typeRemuneration, $request->user()),
            'desactiver' => $service->desactiver($typeRemuneration, $request->user()),
            'reactiver' => $service->reactiver($typeRemuneration, $request->user()),
        };

        return back()->with('success', self::MESSAGES[$action]);
    }
}
