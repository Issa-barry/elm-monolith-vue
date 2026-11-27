<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\Stock\TableauDeBordStockService;
use App\Support\Periodes\Periode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord du stock, Stat-Stock (docs/tableau-de-bord-stock.md) : même droit de lecture
 * (`produits.read`) et même périmètre d'agences que la page Stock. Trois filtres pilotent la page :
 * l'agence, la période (flux seulement) et l'article.
 */
class IndexDashboardStockController extends Controller
{
    public function __invoke(Request $request, TableauDeBordStockService $tableauDeBord): Response
    {
        $this->authorize('viewAny', Produit::class);
        $user = $request->user();

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', TableauDeBordStockService::PERIODES);

        // Agence et article ne sont jamais crus sur parole : l'agence doit faire partie du
        // périmètre de l'utilisateur ; l'article est vérifié par le service, parmi ceux de ce
        // périmètre (une variante d'une autre organisation n'y figure jamais).
        $agences = $tableauDeBord->agencesProposees($user);
        $valides = $request->validate(
            [
                'site_id' => ['nullable', 'string', Rule::in(array_column($agences, 'value'))],
                'article' => ['nullable', 'string', 'max:64'],
            ],
            ['site_id.in' => 'Agence inconnue ou hors de votre périmètre.'],
        );

        $donnees = $tableauDeBord->calculer($user, $periode, $agences, $valides['site_id'] ?? null, $valides['article'] ?? null);

        return Inertia::render('DashboardStock', $donnees);
    }
}
