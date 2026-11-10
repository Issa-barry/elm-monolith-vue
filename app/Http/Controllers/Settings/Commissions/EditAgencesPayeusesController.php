<?php

namespace App\Http\Controllers\Settings\Commissions;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Commission\AgencePayeuseCommissionService;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Agences payeuses (ADR 0061) : agence centralisée et, par catégorie de bénéficiaire,
 * l'agence qui paie les fiches de commission. Réservé à `commissions.configurer_agences_payeuses`.
 */
class EditAgencesPayeusesController extends Controller
{
    public function __invoke(AgencePayeuseCommissionService $service, SiteCentralTresorerieResolver $tresorerie): Response
    {
        $user = auth()->user();
        abort_unless($user->can('commissions.configurer_agences_payeuses'), 403);

        $orgId = $user->organization_id;
        $politique = $service->politique($orgId);
        $principale = $tresorerie->centralOuNull($orgId);

        return Inertia::render('settings/AgencesPayeuses', [
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
            'tresorerie_principale' => $principale ? ['id' => $principale->id, 'nom' => $principale->nom] : null,
            'agence_centralisee_id' => $politique['agence_centralisee_id'],
            'regles' => collect(AgencePayeuseCommissionService::TYPES)->map(fn (string $type) => [
                'type' => $type,
                'libelle' => AgencePayeuseCommissionService::LIBELLES[$type],
                'rattachement' => AgencePayeuseCommissionService::RATTACHEMENTS[$type],
                'mode' => $politique['regles'][$type]['mode'],
                'site_id' => $politique['regles'][$type]['site_id'],
            ])->values(),
        ]);
    }
}
