<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\BesoinTresorerieService;
use App\Services\SiteScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prévision du besoin de trésorerie par agence : montant que le siège doit
 * prévoir pour que chaque agence règle ses commissions livreurs (P1/P2),
 * commissions propriétaires et salaires du mois. Écran de lecture seule —
 * aucun mouvement de fonds n'est créé ici (cf. BesoinTresorerieService).
 */
class BesoinTresorerieController extends Controller
{
    public function __construct(
        private readonly BesoinTresorerieService $besoinTresorerieService,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);

        $rows = $this->besoinTresorerieService->calculerPourMois($orgId, $annee, $mois);
        $rows = $this->restreindreAuxSitesAccessibles($rows, $user);

        return Inertia::render('Comptabilite/Tresorerie/Index', [
            'rows' => $rows,
            'total_general' => BesoinTresorerieService::totalGeneral($rows),
            'filters' => ['annee' => (string) $annee, 'mois' => (string) $mois],
            'is_admin' => $user->isAdmin(),
        ]);
    }

    public function show(Request $request, string $site): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $siteId = $site === 'sans-agence' ? null : $site;

        if ($siteId !== null && ! $user->isAdmin() && ! $this->siteScope->accessibleSiteIds($user)->contains($siteId)) {
            abort(403, "Vous n'avez pas accès à cette agence.");
        }

        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);

        $detail = $this->besoinTresorerieService->detailAgence($orgId, $annee, $mois, $siteId);
        $siteNom = $siteId
            ? (Site::where('organization_id', $orgId)->whereKey($siteId)->value('nom') ?? '—')
            : 'Sans agence';

        return Inertia::render('Comptabilite/Tresorerie/Show', [
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
