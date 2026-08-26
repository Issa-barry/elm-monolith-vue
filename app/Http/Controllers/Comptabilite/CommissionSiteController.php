<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Enums\SiteType;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\PaiementFichePaiement;
use App\Models\Site;
use App\Services\CommissionVenteCalculatorService;
use App\Services\PeriodeComptableService;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionDetailFilters;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionSummaryFormatter;
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
    private const DATE_FORMAT = 'd/m/Y';

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
     * Page détail d'un site — mêmes conventions que CommissionConsultantController::show()
     * (synthèse, détail par commande, dépenses, paiements, historique), sans workflow de
     * validation de période ni paiement direct (can_payer reste false, cf. docblock de classe).
     * Contrairement au consultant, le véhicule ayant livré chaque commande reste affiché : un
     * site voit passer plusieurs véhicules, information pertinente ici (jamais pour un
     * consultant, qui n'a aucun lien avec un véhicule précis).
     */
    public function show(Request $request, string $siteId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $site = Site::find($siteId);
        $nom = $site?->nom ?? '—';

        $allParts = CommissionEnveloppePart::with(['enveloppe.source.vehicule'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->where('beneficiaire_id', $siteId)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)
                ->where('cible_type', CommissionCibleType::CODE_SITE))
            ->orderByDesc('enveloppe_id')
            ->get();

        $filters = CommissionDetailFilters::fromRequest($request);
        $periodeFilter = $filters['periode'];
        if ($periodeFilter !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $periodeFilter)) {
            $periodeFilter = '';
        }

        $fraisDepensesQuery = Depense::with(['depenseType:id,libelle', 'user', 'validateur'])
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
            ->where('beneficiaire_id', $siteId)
            ->where('statut', StatutDepense::VALIDE->value);

        if ($periodeFilter !== '') {
            [$debutDep, $finDep] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $fraisDepensesQuery->whereBetween('date_depense', [$debutDep->toDateString(), $finDep->toDateString().' 23:59:59']);
        }

        $fraisDepensesAffichees = $fraisDepensesQuery->orderByDesc('date_depense')->get();
        $totalFraisDepenses = (float) $fraisDepensesAffichees->sum('montant');

        $earliestCommission = $allParts
            ->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->earned_at !== null)
            ->sortBy(fn (CommissionEnveloppePart $p) => $p->enveloppe->earned_at)
            ->first();
        $earliestDate = $earliestCommission?->enveloppe?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate));

        $filteredParts = $allParts->filter(function (CommissionEnveloppePart $p) use ($periodeFilter) {
            if ($periodeFilter === '') {
                return true;
            }
            $earnedAt = $p->enveloppe?->earned_at;

            return $earnedAt && PeriodeComptableService::codeFor('site', Carbon::parse($earnedAt)) === $periodeFilter;
        });

        // net/verse/reste restent calculés exclusivement sur les parts déjà actives (jamais
        // CREEE) — jamais mélangées à une commission pas encore éligible au paiement, même
        // logique que les autres écrans détail Commission (décision produit du 20/08/2026).
        $activeParts = $filteredParts->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);
        $resume = CommissionVenteCalculatorService::calculerResume(
            (float) $activeParts->sum('montant_brut'),
            0.0,
            (float) $activeParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
            $totalFraisDepenses,
            (float) $activeParts->sum('montant_verse'),
        );
        $buckets = CommissionKpiBuckets::calculer($filteredParts);

        $historiqueCommandes = $filteredParts
            ->groupBy('enveloppe_id')
            ->map(function (Collection $partsGroup) {
                $first = $partsGroup->first();
                $enveloppe = $first->enveloppe;
                $source = $enveloppe?->source;
                $periodeCode = $enveloppe?->earned_at
                    ? PeriodeComptableService::codeFor('site', Carbon::parse($enveloppe->earned_at))
                    : null;

                $montantAPayer = (float) $partsGroup->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
                $montantVerse = (float) $partsGroup->sum('montant_verse');

                return [
                    'commission_id' => $enveloppe?->id,
                    'reference' => $source?->reference,
                    'date' => $enveloppe?->earned_at ? Carbon::parse($enveloppe->earned_at)->format(self::DATE_FORMAT) : null,
                    'vehicule' => $source?->vehicule ? [
                        'id' => $source->vehicule->id,
                        'nom' => $source->vehicule->nom_vehicule,
                        'immatriculation' => $source->vehicule->immatriculation,
                    ] : null,
                    'montant_brut' => (float) $partsGroup->sum('montant_brut'),
                    'montant' => $montantAPayer,
                    'paye' => $montantVerse,
                    'reste' => max(0.0, $montantAPayer - $montantVerse),
                    'statut' => $first->statut?->label(),
                    'statut_dot_class' => $first->statut instanceof StatutCommission ? $first->statut->dotClass() : 'bg-zinc-400 dark:bg-zinc-500',
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                ];
            })
            ->values();

        $historiquePaiementsQuery = PaiementFichePaiement::with('createur')
            ->whereHas('fiche', fn ($q) => $q->where('organization_id', $orgId)
                ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_SITE)
                ->where('beneficiaire_id', $siteId))
            ->orderByDesc('date_paiement');

        if ($periodeFilter !== '') {
            [$debutPaiement, $finPaiement] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $historiquePaiementsQuery->whereBetween('date_paiement', [$debutPaiement->toDateString(), $finPaiement->toDateString().' 23:59:59']);
        }

        $historiquePaiements = $historiquePaiementsQuery
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'paid_at' => $p->date_paiement?->format(self::DATE_FORMAT),
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement instanceof ModePaiement
                    ? $p->mode_paiement->label()
                    : (string) $p->mode_paiement,
                'note' => $p->note,
                'created_by' => $p->createur?->name,
            ]);

        $periodeRange = ['debut' => null, 'fin' => null];
        if ($periodeFilter !== '') {
            [$debutRange, $finRange] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $periodeRange = ['debut' => $debutRange->toDateString(), 'fin' => $finRange->toDateString()];
        }

        return Inertia::render('Comptabilite/CommissionSite/Show', [
            'site' => [
                'id' => $siteId,
                'nom' => $nom,
                'code' => $site?->code,
                'telephone' => null,
            ],
            'commission_summary' => CommissionSummaryFormatter::format(
                $resume['brut'],
                $totalFraisDepenses,
                $resume['net'],
                $resume['verse'],
                $resume['reste'],
                $buckets,
            ),
            'expenses' => $fraisDepensesAffichees->map(fn ($d) => [
                'id' => $d->id,
                'date' => $d->date_depense->format(self::DATE_FORMAT),
                'type' => $d->depenseType?->libelle ?? '—',
                'commentaire' => $d->commentaire,
                'saisi_par' => $d->user?->name,
                'validateur' => $d->validateur?->name,
                'montant' => (float) $d->montant,
            ])->values(),
            'commission_details' => $historiqueCommandes,
            'payments' => $historiquePaiements,
            'modes_paiement' => ModePaiement::options(),
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'filters' => [
                'periode' => $periodeFilter,
                'vehicule_ids' => [],
                'site_ids' => [],
                'periode_range' => $periodeRange,
            ],
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
