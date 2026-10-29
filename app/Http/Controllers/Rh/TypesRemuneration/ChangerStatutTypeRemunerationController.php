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
        // Date d'effet de la désactivation : obligatoire dès qu'un salarié bénéficie du type (ADR 0039).
        $data = $request->validate(['date_effet' => ['nullable', 'date_format:Y-m-d']], [
            'date_effet.date_format' => 'Date d\'effet invalide.',
        ]);

        match ($action) {
            'valider' => $service->valider($typeRemuneration, $request->user()),
            'fermer' => $service->fermer($typeRemuneration, $request->user()),
            'desactiver' => $service->desactiver($typeRemuneration, $request->user(), $data['date_effet'] ?? null),
            'reactiver' => $service->reactiver($typeRemuneration, $request->user()),
        };

        return back()->with('success', self::MESSAGES[$action]);
    }
}
