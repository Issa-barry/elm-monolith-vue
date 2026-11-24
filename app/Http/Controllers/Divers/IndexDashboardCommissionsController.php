<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Services\Commission\TableauDeBordCommissionsService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : mêmes droits de lecture
 * et mêmes périmètres d'agence que les écrans Comptabilité > Commissions. Deux filtres pilotent
 * toute la page : la période et l'agence.
 */
class IndexDashboardCommissionsController extends Controller
{
    public function __invoke(Request $request, TableauDeBordCommissionsService $tableauDeBord): Response
    {
        $user = $request->user();
        abort_unless($user->canReadCommissions(), 403);

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordCommissionsService::PERIODES);

        // L'agence demandée n'est jamais crue sur parole : elle doit faire partie des agences que
        // l'utilisateur peut voir, sinon la requête est refusée avec un message.
        $agences = $tableauDeBord->agencesProposees($user);
        $agence = $request->validate(
            ['site_id' => ['nullable', 'string', Rule::in(array_column($agences, 'value'))]],
            ['site_id.in' => 'Agence inconnue ou hors de votre périmètre.'],
        )['site_id'] ?? null;

        return Inertia::render('DashboardCommissions', $tableauDeBord->calculer($user, $periode, $agences, $agence));
    }
}
