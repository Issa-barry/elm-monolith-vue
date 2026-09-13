<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\TypeSupportTresorerie;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Situation de trésorerie : solde ACTUEL de chaque support par site, calculé
 * depuis le grand livre (TresorerieDisponibiliteService::situationParSupport())
 * — jamais une nouvelle logique de calcul parallèle. Complète Supports de
 * trésorerie (configuration) et Mouvements de fonds (historique des
 * transferts) : cet écran répond à « combien y a-t-il actuellement dans
 * chaque caisse/banque/mobile money ? » (revue produit du 2026-09-13).
 * Toujours en lecture seule — les entrées/sorties détaillées restent la
 * responsabilité du Journal financier, pas dupliquées ici.
 */
class SituationTresorerieController extends Controller
{
    public function __construct(
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('tresorerie.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : now();

        $sitesQuery = Site::where('organization_id', $orgId)->orderBy('nom');
        if (! $isAdmin) {
            $sitesQuery->whereIn('id', $this->siteScope->accessibleSiteIds($user));
        }
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];
        if ($filtreSiteIds !== []) {
            $sitesQuery->whereIn('id', $filtreSiteIds);
        }
        $sites = $sitesQuery->get(['id', 'nom']);

        $supports = $this->disponibilite->situationParSupport($orgId, $date)
            ->whereIn('site_id', $sites->pluck('id')->all());

        $types = TypeSupportTresorerie::cases();

        $rows = $sites->map(function (Site $site) use ($supports, $types) {
            $supportsSite = $supports->where('site_id', $site->id);
            $parType = [];
            foreach ($types as $type) {
                $parType[$type->value] = round((float) $supportsSite->where('type', $type->value)->sum('solde'), 2);
            }

            return [
                'site_id' => $site->id,
                'site_nom' => $site->nom,
                'par_type' => $parType,
                'total' => round((float) $supportsSite->sum('solde'), 2),
            ];
        })->values();

        $totalGeneral = [
            'par_type' => collect($types)->mapWithKeys(
                fn (TypeSupportTresorerie $t) => [$t->value => round((float) $rows->sum(fn (array $r) => $r['par_type'][$t->value]), 2)]
            )->all(),
            'total' => round((float) $rows->sum('total'), 2),
        ];

        return Inertia::render('Comptabilite/Tresorerie/Situation/Index', [
            'rows' => $rows,
            'total_general' => $totalGeneral,
            'type_options' => TypeSupportTresorerie::options(),
            'filters' => [
                'date' => $date->toDateString(),
                'site_ids' => $filtreSiteIds,
            ],
            'sites' => $isAdmin
                ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->nom])
                : collect(),
            'is_admin' => $isAdmin,
        ]);
    }

    public function show(Request $request, string $site): Response
    {
        abort_unless(auth()->user()->can('tresorerie.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;

        if (! $user->isAdmin() && ! $this->siteScope->accessibleSiteIds($user)->contains($site)) {
            abort(403, "Vous n'avez pas accès à cette agence.");
        }

        $siteModel = Site::where('organization_id', $orgId)->findOrFail($site);

        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : now();

        $supports = $this->disponibilite->situationParSupport($orgId, $date)
            ->where('site_id', $siteModel->id)
            ->sortBy('libelle')
            ->values();

        return Inertia::render('Comptabilite/Tresorerie/Situation/Show', [
            'site' => ['id' => $siteModel->id, 'nom' => $siteModel->nom],
            'supports' => $supports,
            'total' => round((float) $supports->sum('solde'), 2),
            'filters' => ['date' => $date->toDateString()],
        ]);
    }
}
