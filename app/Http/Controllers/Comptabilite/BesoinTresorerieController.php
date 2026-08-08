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
        $isAdmin = $user->isAdmin();

        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);
        // Filtre Agence : uniquement pertinent pour un admin (même pattern que
        // SalaireController) — un non-admin est toujours cantonné à ses sites
        // via restreindreAuxSitesAccessibles(), le sélecteur reste verrouillé
        // côté UI (DataFilters gère ce cadenas automatiquement).
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $rows = $this->besoinTresorerieService->calculerPourMois($orgId, $annee, $mois);
        $rows = $this->restreindreAuxSitesAccessibles($rows, $user);

        if ($isAdmin && $filtreSiteIds !== []) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['site_id'] !== null && in_array($row['site_id'], $filtreSiteIds, true),
            ));
        }

        return Inertia::render('Comptabilite/Tresorerie/Index', [
            'rows' => $rows,
            'total_general' => BesoinTresorerieService::totalGeneral($rows),
            'filters' => [
                'annee' => (string) $annee,
                'mois' => (string) $mois,
                'site_ids' => $filtreSiteIds,
            ],
            'is_admin' => $isAdmin,
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
        // Provenance de l'onglet cliqué sur l'index (p1/p2/mensuel) — sert
        // uniquement à savoir quelles sections afficher ici, aucune incidence
        // sur le calcul (le détail complet reste chargé dans tous les cas).
        $echeance = $request->input('echeance', 'mensuel');
        if (! in_array($echeance, ['p1', 'p2', 'mensuel'], true)) {
            $echeance = 'mensuel';
        }

        $detail = $this->besoinTresorerieService->detailAgence($orgId, $annee, $mois, $siteId);
        $siteNom = $siteId
            ? (Site::where('organization_id', $orgId)->whereKey($siteId)->value('nom') ?? '—')
            : 'Sans agence';

        return Inertia::render('Comptabilite/Tresorerie/Show', [
            'site' => ['id' => $siteId, 'nom' => $siteNom],
            'detail' => $detail,
            'filters' => ['annee' => (string) $annee, 'mois' => (string) $mois, 'echeance' => $echeance],
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
