<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Vehicules\TableauDeBordVehiculesService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord du parc, Stat-Véhicules (docs/tableau-de-bord-vehicules.md) : ouvert avec le droit
 * de lecture des véhicules (`vehicules.read`) ; chaque bloc d'activité ou de dépenses n'est calculé
 * et envoyé qu'avec la permission et le module de sa source (ventes, logistique, dépenses). Deux
 * filtres : la période et l'agence.
 */
class IndexDashboardVehiculesController extends Controller
{
    public function __invoke(Request $request, TableauDeBordVehiculesService $tableauDeBord): Response
    {
        $this->authorize('viewAny', Vehicule::class);
        $user = $request->user();

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordVehiculesService::PERIODES);

        // L'agence demandée doit faire partie du périmètre de l'utilisateur, sinon refus avec un message.
        $agences = $tableauDeBord->agencesProposees($user);
        $agence = $request->validate(
            ['site_id' => ['nullable', 'string', Rule::in(array_column($agences, 'value'))]],
            ['site_id.in' => 'Agence inconnue ou hors de votre périmètre.'],
        )['site_id'] ?? null;

        return Inertia::render('DashboardVehicules', $tableauDeBord->calculer($user, $periode, $agences, $agence));
    }
}
