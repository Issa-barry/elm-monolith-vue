<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Services\Rapports\RapportActivitePresenter;
use App\Services\Rapports\RapportPerimetreResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rapport d'activité (`rapports.read`) : agences accessibles à l'utilisateur, tous agents ou un
 * agent choisi — périmètre imposé par RapportPerimetreResolver, jamais par les seuls paramètres.
 */
class IndexRapportActiviteController extends Controller
{
    public function __invoke(Request $request, RapportPerimetreResolver $perimetres, RapportActivitePresenter $presenter): Response
    {
        $user = $request->user();
        $perimetre = $perimetres->pourRapport($user, $request);

        return Inertia::render('Rapports/Activite', $presenter->props($user, $perimetre));
    }
}
