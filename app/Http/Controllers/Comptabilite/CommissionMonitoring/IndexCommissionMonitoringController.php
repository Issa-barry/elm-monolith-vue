<?php

namespace App\Http\Controllers\Comptabilite\CommissionMonitoring;

use App\Enums\CommissionAnomalieStatut;
use App\Enums\CommissionMotifNonGeneration;
use App\Http\Controllers\Controller;
use App\Models\CommissionProcessus;
use App\Models\Site;
use App\Services\Commission\CommissionMonitoringService;
use App\Services\SiteScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commissions → Monitoring : file de traitement des commissions attendues mais non générées
 * (cf. CommissionMonitoringService). Lecture sous la même règle que les autres écrans
 * Commissions (User::canReadCommissions()) ; données restreintes aux agences de l'utilisateur.
 */
class IndexCommissionMonitoringController extends Controller
{
    private const PAR_PAGE = 50;

    public const STATUT_OUVERTES = 'ouvertes';

    public const STATUT_TOUTES = 'toutes';

    public function __invoke(Request $request, CommissionMonitoringService $monitoring, SiteScopeService $siteScope): Response
    {
        $user = $request->user();
        abort_unless($user->canReadCommissions(), 403);

        $orgId = $user->organization_id;
        $siteIdsAutorises = $user->voitToutesLesAgences() ? null : $siteScope->accessibleSiteIds($user);

        $filtres = [
            'site_ids' => array_values(array_filter((array) $request->input('site_ids', []), 'is_string')),
            'statut' => $this->texte($request, 'statut') ?: self::STATUT_OUVERTES,
            'reference' => $this->texte($request, 'reference'),
            'cible' => $this->texte($request, 'cible'),
            'processus' => $this->texte($request, 'processus'),
            'motif' => $this->texte($request, 'motif'),
            'detection_debut' => $this->texte($request, 'detection_debut'),
            'detection_fin' => $this->texte($request, 'detection_fin'),
            'tentatives_min' => $this->texte($request, 'tentatives_min'),
        ];

        $anomalies = $this->filtrer($monitoring->anomalies($orgId, $siteIdsAutorises), $filtres);

        $kpis = [
            'non_generees' => $anomalies->where('statut', CommissionAnomalieStatut::NON_GENEREE->value)->count(),
            'echecs_recurrents' => $anomalies->where('statut', CommissionAnomalieStatut::ECHEC_RECURRENT->value)->count(),
            'regularisees' => $anomalies->where('statut', CommissionAnomalieStatut::REGULARISEE->value)->count(),
            'sans_objet' => $anomalies->where('statut', CommissionAnomalieStatut::SANS_OBJET->value)->count(),
            'montant_en_attente' => (float) $anomalies->where('ouverte', true)->sum('montant_attendu'),
        ];

        $affichees = $anomalies->filter(fn (array $a) => match ($filtres['statut']) {
            self::STATUT_TOUTES => true,
            self::STATUT_OUVERTES => $a['ouverte'],
            default => $a['statut'] === $filtres['statut'],
        })->values();

        $page = max(1, (int) $request->input('page', 1));
        $paginees = new LengthAwarePaginator(
            $affichees->forPage($page, self::PAR_PAGE)->values(),
            $affichees->count(),
            self::PAR_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $sites = $siteIdsAutorises === null
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : Site::whereIn('id', $siteIdsAutorises)->orderBy('nom')->get(['id', 'nom']);

        return Inertia::render('Comptabilite/CommissionMonitoring/Index', [
            'anomalies' => $paginees,
            'kpis' => $kpis,
            'filtres' => $filtres,
            'sites' => $sites,
            'statut_options' => [
                ['value' => self::STATUT_OUVERTES, 'label' => 'Ouvertes (à traiter)'],
                ...collect(CommissionAnomalieStatut::cases())->map(fn (CommissionAnomalieStatut $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
                ['value' => self::STATUT_TOUTES, 'label' => 'Toutes'],
            ],
            'cible_options' => CommissionMonitoringService::optionsCible(),
            'motif_options' => collect(CommissionMotifNonGeneration::cases())->map(fn (CommissionMotifNonGeneration $m) => ['value' => $m->value, 'label' => $m->label()])->all(),
            'processus_options' => CommissionProcessus::where('organization_id', $orgId)->orderBy('libelle')->get(['code', 'libelle'])
                ->map(fn (CommissionProcessus $p) => ['value' => $p->code, 'label' => $p->libelle ?? $p->code])->all(),
            'seuil_echec_recurrent' => CommissionMonitoringService::SEUIL_ECHEC_RECURRENT,
            'can_relancer' => $user->can('commissions.update'),
        ]);
    }

    /**
     * Tous les filtres sauf le statut : les indicateurs du haut comptent chaque statut sur ce
     * même périmètre.
     *
     * @param  Collection<int, array<string, mixed>>  $anomalies
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    private function filtrer(Collection $anomalies, array $f): Collection
    {
        $debut = $this->date($f['detection_debut'])?->startOfDay();
        $fin = $this->date($f['detection_fin'])?->endOfDay();
        $recherche = mb_strtolower($f['reference']);

        return $anomalies->filter(function (array $a) use ($f, $debut, $fin, $recherche) {
            if ($f['site_ids'] !== [] && ! in_array($a['site_id'], $f['site_ids'], true)) {
                return false;
            }
            if ($recherche !== '' && ! collect([$a['reference'], $a['vehicule_nom'], $a['client']])
                ->contains(fn (?string $v) => $v !== null && str_contains(mb_strtolower($v), $recherche))) {
                return false;
            }
            if ($f['cible'] !== '' && $a['cible'] !== $f['cible']) {
                return false;
            }
            if ($f['processus'] !== '' && $a['processus_code'] !== $f['processus']) {
                return false;
            }
            if ($f['motif'] !== '' && ! in_array($f['motif'], array_column($a['erreurs'], 'code'), true)) {
                return false;
            }
            if (($debut || $fin) && $a['detectee_le_iso'] !== null) {
                $detection = Carbon::parse($a['detectee_le_iso']);
                if (($debut && $detection->lt($debut)) || ($fin && $detection->gt($fin))) {
                    return false;
                }
            }

            return $f['tentatives_min'] === '' || $a['nb_tentatives_echouees'] >= (int) $f['tentatives_min'];
        })->values();
    }

    private function texte(Request $request, string $cle): string
    {
        $valeur = $request->input($cle, '');

        return trim(is_array($valeur) ? (string) reset($valeur) : (string) $valeur);
    }

    private function date(string $valeur): ?Carbon
    {
        if ($valeur === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur);
        } catch (\Throwable) {
            return null;
        }
    }
}
