<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Services\Depenses\TableauDeBordDepensesService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord des dépenses, Stat-Dépenses (docs/tableau-de-bord-depenses.md) : même droit de
 * lecture (`depenses.read`) et même périmètre (DepensePerimetre) que la liste des dépenses. Deux
 * filtres pilotent toute la page : la période et l'agence.
 */
class IndexDashboardDepensesController extends Controller
{
    public function __invoke(Request $request, TableauDeBordDepensesService $tableauDeBord): Response
    {
        $this->authorize('viewAny', Depense::class);
        $user = $request->user();

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordDepensesService::PERIODES);

        // L'agence demandée n'est jamais crue sur parole : elle doit faire partie des agences que
        // l'utilisateur peut consulter, sinon la requête est refusée avec un message.
        $agences = $tableauDeBord->agencesProposees($user);
        $agence = $request->validate(
            ['site_id' => ['nullable', 'string', Rule::in(array_column($agences, 'value'))]],
            ['site_id.in' => 'Agence inconnue ou hors de votre périmètre.'],
        )['site_id'] ?? null;

        return Inertia::render('DashboardDepenses', $tableauDeBord->calculer($user, $periode, $agences, $agence));
    }
}
