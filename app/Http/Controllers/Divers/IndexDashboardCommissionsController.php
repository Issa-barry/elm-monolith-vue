<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Services\Commission\TableauDeBordCommissionsService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : mêmes droits de lecture
 * et mêmes périmètres d'agence que les écrans Comptabilité > Commissions.
 */
class IndexDashboardCommissionsController extends Controller
{
    public function __invoke(Request $request, TableauDeBordCommissionsService $tableauDeBord): Response
    {
        abort_unless($request->user()->canReadCommissions(), 403);

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordCommissionsService::PERIODES);

        return Inertia::render('DashboardCommissions', $tableauDeBord->calculer($request->user(), $periode));
    }
}
