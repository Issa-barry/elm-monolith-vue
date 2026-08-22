<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\FinancementAgenceService;
use App\Services\Tresorerie\ObligationsAgenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Remplace Comptabilite/Tresorerie (BesoinTresorerieController) : ne montre
 * plus les obligations restantes seules ("Total à envoyer" trompeur, cf.
 * compte-rendu du chantier), mais le vrai complément à financer une fois la
 * trésorerie déjà disponible en agence déduite (FinancementAgenceService).
 * Toujours en lecture seule — les mouvements de fonds eux-mêmes se créent
 * depuis MouvementFondsController.
 */
class FinancementAgenceController extends Controller
{
    public function __construct(
        private readonly FinancementAgenceService $financement,
        private readonly ObligationsAgenceService $obligations,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('tresorerie.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);
        $echeance = $request->input('echeance', 'mensuel');
        if (! in_array($echeance, ['p1', 'p2', 'mensuel'], true)) {
            $echeance = 'mensuel';
        }

        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $rows = $this->financement->calculerPourEcheance($orgId, $annee, $mois, $echeance);
        $rows = $this->restreindreAuxSitesAccessibles($rows, $user);

        if ($isAdmin && $filtreSiteIds !== []) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['site_id'] !== null && in_array($row['site_id'], $filtreSiteIds, true),
            ));
        }

        return Inertia::render('Comptabilite/Financement/Index', [
            'rows' => $rows,
            'total_general' => $this->financement->totalGeneral($rows),
            'filters' => [
                'annee' => (string) $annee,
                'mois' => (string) $mois,
                'echeance' => $echeance,
                'site_ids' => $filtreSiteIds,
            ],
            'sites' => $isAdmin
                ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])
                : collect(),
            'is_admin' => $isAdmin,
        ]);
    }

    public function show(Request $request, string $site): Response
    {
        abort_unless(auth()->user()->can('tresorerie.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $siteId = $site === 'sans-agence' ? null : $site;

        if ($siteId !== null && ! $user->isAdmin() && ! $this->siteScope->accessibleSiteIds($user)->contains($siteId)) {
            abort(403, "Vous n'avez pas accès à cette agence.");
        }

        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);

        $detail = $this->obligations->detailAgence($orgId, $annee, $mois, $siteId);
        $siteNom = $siteId
            ? (Site::where('organization_id', $orgId)->whereKey($siteId)->value('nom') ?? '—')
            : 'Sans agence';

        return Inertia::render('Comptabilite/Financement/Show', [
            'site' => ['id' => $siteId, 'nom' => $siteNom],
            'detail' => $detail,
            'filters' => ['annee' => (string) $annee, 'mois' => (string) $mois],
        ]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function restreindreAuxSitesAccessibles(array $rows, User $user): array
    {
        if ($user->isAdmin()) {
            return $rows;
        }

        $siteIds = $this->siteScope->accessibleSiteIds($user);

        return array_values(array_filter(
            $rows,
            fn (array $row) => $row['site_id'] !== null && $siteIds->contains($row['site_id']),
        ));
    }
}
