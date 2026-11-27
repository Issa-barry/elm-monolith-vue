<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Services\Rh\TableauDeBordRhService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord RH, Stat-RH (docs/tableau-de-bord-rh.md) : ouvert à qui consulte au moins une
 * des listes Employés, Contrats ou Absences ; chaque bloc n'est envoyé qu'avec la permission de sa
 * liste. Périmètre d'agences des listes RH. Deux filtres : la période et l'agence.
 */
class IndexDashboardRhController extends Controller
{
    public function __invoke(Request $request, TableauDeBordRhService $tableauDeBord): Response
    {
        $user = $request->user();
        abort_unless(TableauDeBordRhService::peutConsulter($user), 403);

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordRhService::PERIODES);

        // L'agence demandée doit faire partie du périmètre de l'utilisateur, sinon refus avec un message.
        $agences = $tableauDeBord->agencesProposees($user);
        $agence = $request->validate(
            ['site_id' => ['nullable', 'string', Rule::in(array_column($agences, 'value'))]],
            ['site_id.in' => 'Agence inconnue ou hors de votre périmètre.'],
        )['site_id'] ?? null;

        return Inertia::render('DashboardRh', $tableauDeBord->calculer($user, $periode, $agences, $agence));
    }
}
