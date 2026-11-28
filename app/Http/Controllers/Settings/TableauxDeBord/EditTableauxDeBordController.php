<?php

namespace App\Http\Controllers\Settings\TableauxDeBord;

use App\Features\TableauDeBordFeature;
use App\Http\Controllers\Controller;
use App\Services\TableauDeBordService;
use Inertia\Inertia;
use Inertia\Response;

/** Paramètres → Tableaux de bord : activation de chaque tableau « Stat- » pour l'organisation (ADR 0071). */
class EditTableauxDeBordController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $org = auth()->user()->loadMissing('organization')->organization;
        abort_unless($org, 403);

        $libelles = TableauDeBordFeature::libelles();
        $descriptions = TableauDeBordFeature::descriptions();

        $tableaux = collect(TableauDeBordService::allForOrg($org))
            ->map(fn (bool $actif, string $tableau) => [
                'cle' => $tableau,
                'libelle' => $libelles[$tableau],
                'description' => $descriptions[$tableau],
                'actif' => $actif,
            ])
            ->values();

        return Inertia::render('settings/TableauxDeBord', [
            'tableaux' => $tableaux,
        ]);
    }
}
