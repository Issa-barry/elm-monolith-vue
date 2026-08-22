<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\SiteType;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\Site;
use App\Services\CommissionVenteCalculatorService;
use App\Services\PeriodeComptableService;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionKpiBuckets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écran "Commission sites" — mirroring CommissionVenteController, source CommissionEnveloppePart
 * filtrée sur beneficiaire_type=site ET enveloppe.cible_type=site (jamais un simple
 * beneficiaire_type=site seul, cf. convention déjà établie pour proprietaire/livreur). Le
 * bénéficiaire EST le site métier de l'opération — jamais un gérant, un employé, ou une équipe :
 * aucune notion d'éligibilité, de fonction, de rôle ou d'affectation n'intervient ici. Mêmes
 * conventions que les autres écrans Commission v2 : cartes de synthèse, DataFilters, StatusDot,
 * exports, jamais de paiement direct (chaîne unique via Comptabilité > Fiches de paiement).
 */
class CommissionSiteController extends Controller
{
    public function __construct(private SiteScopeService $siteScope) {}

    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return trim(is_array($value) ? (string) reset($value) : (string) $value);
    }

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        [$list, $meta] = $this->resolveBeneficiaires($request);
        $orgId = auth()->user()->organization_id;

        $kpis = [
            'commissions_generees' => (float) $list->sum('total_genere'),
            'depenses' => (float) $list->sum('total_frais'),
            'net_valide' => (float) $list->sum('total_net_cumule'),
            'reste_a_payer' => (float) $list->sum('solde_restant'),
        ];

        $earliestDate = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)->where('cible_type', CommissionCibleType::CODE_SITE))
            ->join('commission_enveloppes', 'commission_enveloppes.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->min('commission_enveloppes.earned_at');

        $periodesDisponibles = $earliestDate
            ? PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate))
            : [];

        return Inertia::render('Comptabilite/CommissionSite/Index', [
            'beneficiaires' => $list,
            'kpis' => $kpis,
            'search' => $meta['search'],
            'filtre_statut' => $meta['filtre_statut'],
            'filtre_site_ids' => $meta['filtre_site_ids'],
            'filtre_categorie_id' => $meta['filtre_categorie_id'],
            'filtre_site_type' => $meta['filtre_site_type'],
            'selected_periode' => $meta['filtre_periode'],
            'periodes_disponibles' => $periodesDisponibles,
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
            'site_types' => $meta['site_types'],
            'can_payer' => false,
        ]);
    }

    /**
     * Construit la liste des bénéficiaires (= sites) filtrée — unique source pour index() ET les
     * exports, jamais deux implémentations divergentes du même périmètre visible.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveBeneficiaires(Request $request): array
    {
        $user = auth()->user();
        $orgId = $user->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = $this->scalarInput($request, 'statut');
        $filtrePeriode = $this->scalarInput($request, 'periode');
        if ($filtrePeriode !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $filtrePeriode)) {
            $filtrePeriode = '';
        }
        $filtreCategorieId = $this->scalarInput($request, 'categorie_id');
        $filtreSiteType = $this->scalarInput($request, 'site_type');

        $isAdmin = $user->isAdmin();
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $query = CommissionEnveloppePart::with([
            'enveloppe.source.site:id,nom,code,type',
            'enveloppe.lignes.categorieSnapshot:id,nom',
        ])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId)
                    ->where('cible_type', CommissionCibleType::CODE_SITE);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });

        if ($isAdmin && ! empty($filtreSiteIds)) {
            $query->whereIn('beneficiaire_id', $filtreSiteIds);
        } elseif (! $isAdmin && ! empty($siteIds)) {
            $query->whereIn('beneficiaire_id', $siteIds);
        }

        if ($filtreCategorieId !== '') {
            $query->whereHas('enveloppe.lignes', fn ($q) => $q->where('categorie_id_snapshot', $filtreCategorieId));
        }

        if ($filtreSiteType !== '') {
            $query->whereHas('enveloppe.source.site', fn ($q) => $q->where('type', $filtreSiteType));
        }

        $allParts = $query->get();
        $partsParSite = $allParts->groupBy('beneficiaire_id');

        $categoriesParSite = $partsParSite->map(fn ($parts) => $parts
            ->flatMap(fn (CommissionEnveloppePart $p) => $p->enveloppe->lignes->pluck('categorieSnapshot.nom'))
            ->filter()->unique()->sort()->values());

        $allSiteIds = $partsParSite->keys()->map(fn ($id) => (string) $id)->all();
        $fraisDepensesParSite = $this->fraisDepensesParSite($orgId, $allSiteIds, $filtrePeriode);
        $sitesById = Site::whereIn('id', $allSiteIds)->get()->keyBy('id');

        $beneficiaires = $partsParSite->map(function (Collection $parts, string $siteId) use (
            $categoriesParSite, $fraisDepensesParSite, $sitesById,
        ) {
            $site = $sitesById->get($siteId);
            $fraisDepenses = $fraisDepensesParSite[$siteId] ?? 0.0;

            $partsPayables = $parts->filter(
                fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE
            );

            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $partsPayables->sum('montant_brut'),
                0.0,
                (float) $partsPayables->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
                $fraisDepenses,
                (float) $partsPayables->sum('montant_verse'),
            );

            $buckets = CommissionKpiBuckets::calculer($parts);

            $statutGlobal = $partsPayables->isEmpty() && $buckets['en_attente_periode'] > 0.009
                ? StatutCommission::CREEE->value
                : $resume['statut'];

            return [
                'beneficiaire_id' => $siteId,
                'beneficiaire_nom' => $site?->nom ?? '—',
                'site_code' => $site?->code,
                'site_type' => $site?->type?->value,
                'site_type_label' => $site?->type?->label(),
                'categories' => $categoriesParSite->get($siteId, collect())->values()->all(),
                'total_brut_cumule' => $resume['brut'],
                'total_frais' => $resume['frais'],
                'total_net_cumule' => $resume['net'],
                'total_verse' => $resume['verse'],
                'solde_restant' => $resume['reste'],
                'nb_commandes' => $parts->pluck('enveloppe_id')->unique()->count(),
                'statut_global' => $statutGlobal,
                'statut_label' => $this->statutLabel($statutGlobal),
                'total_genere' => $buckets['total_genere'],
                'en_attente_periode' => $buckets['en_attente_periode'],
                'payable' => $buckets['payable'],
                // Jamais payable depuis cet écran, cf. docblock de classe.
                'can_pay' => false,
            ];
        })->values();

        if ($filtreStatut !== '') {
            $beneficiaires = $beneficiaires->filter(fn ($b) => $b['statut_global'] === $filtreStatut);
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => str_contains(mb_strtolower((string) $b['beneficiaire_nom']), $s)
                    || str_contains(mb_strtolower((string) $b['site_code']), $s)
            );
        }

        $siteTypes = collect(SiteType::cases())
            ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])
            ->values();

        return [$beneficiaires->sortBy('beneficiaire_nom')->values(), [
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_periode' => $filtrePeriode,
            'filtre_site_ids' => $filtreSiteIds,
            'filtre_categorie_id' => $filtreCategorieId,
            'filtre_site_type' => $filtreSiteType,
            'site_types' => $siteTypes,
        ]];
    }

    private function statutLabel(string $statut): string
    {
        return match ($statut) {
            StatutCommission::CREEE->value => 'Créée',
            StatutCommission::IMPAYE->value => 'Impayé',
            StatutCommission::PARTIEL->value => 'Partiel',
            StatutCommission::PAYE->value => 'Payé',
            StatutCommission::ANNULEE->value => 'Annulée',
            default => $statut,
        };
    }

    /**
     * Dépenses attribuées directement au site (beneficiaire_type=site) — même mécanisme
     * générique que pour les autres bénéficiaires. Renvoie toujours un tableau vide aujourd'hui
     * tant qu'aucune dépense ne peut encore être créée avec ce bénéficiaire côté module Dépenses
     * (hors périmètre de cette mission, cf. rapport de livraison) : la requête reste posée pour
     * ne rien casser le jour où cette capacité existera.
     *
     * @param  array<int, string>  $siteIds
     */
    private function fraisDepensesParSite(string $orgId, array $siteIds, string $periode = ''): array
    {
        if (empty($siteIds)) {
            return [];
        }

        $query = Depense::where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->whereIn('beneficiaire_id', $siteIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId);

        if ($periode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($periode);
            $query->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
        }

        return $query->get(['beneficiaire_id', 'montant'])
            ->groupBy('beneficiaire_id')
            ->map(fn ($d) => (float) $d->sum('montant'))
            ->toArray();
    }

    // ── Exports ───────────────────────────────────────────────────────────────
    // Reprennent exactement le périmètre visible dans index() (mêmes filtres) — seule
    // la sortie diffère.

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);
        abort_unless(auth()->user()->can('commissions.exporter'), 403);

        [$rows] = $this->resolveBeneficiaires($request);
        $filename = 'commissions-sites-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Site', 'Code', 'Type', 'Catégories', 'Généré (GNF)', 'Brut validé (GNF)', 'Dépenses (GNF)', 'Net validé (GNF)', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['site_code'] ?? '',
                    $row['site_type_label'] ?? '',
                    implode(' / ', $row['categories'] ?? []),
                    number_format((float) $row['total_genere'], 0, ',', ' '),
                    number_format((float) $row['total_brut_cumule'], 0, ',', ' '),
                    number_format((float) $row['total_frais'], 0, ',', ' '),
                    number_format((float) $row['total_net_cumule'], 0, ',', ' '),
                    number_format((float) $row['total_verse'], 0, ',', ' '),
                    number_format((float) $row['solde_restant'], 0, ',', ' '),
                    $row['statut_label'],
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);
        abort_unless(auth()->user()->can('commissions.exporter'), 403);

        [$rows] = $this->resolveBeneficiaires($request);
        $org = Organization::find(auth()->user()->organization_id);
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.sites', [
            'title' => 'Commissions des sites',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'rows' => $rows,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-sites-'.now()->format('Y-m-d').'.pdf');
    }
}
