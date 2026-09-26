<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Services\Rapports\RapportActivitePresenter;
use App\Services\Rapports\RapportPerimetreResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Ma situation » (`rapports.read_own`) : le rapport d'activité limité à l'utilisateur connecté —
 * l'agent est imposé côté serveur, tout paramètre `agent_id` ou `site_ids` est ignoré.
 */
class IndexMaSituationController extends Controller
{
    public function __invoke(Request $request, RapportPerimetreResolver $perimetres, RapportActivitePresenter $presenter): Response
    {
        $user = $request->user();
        $perimetre = $perimetres->pourMaSituation($user, $request);

        return Inertia::render('Rapports/Activite', $presenter->props($user, $perimetre));
    }
}
