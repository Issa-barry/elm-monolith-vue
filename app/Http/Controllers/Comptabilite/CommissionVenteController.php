<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementCommissionVente;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\CommissionVenteCalculatorService;
use App\Services\CommissionVentePaiementService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use App\Services\PeriodePayabilityChecker;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionDetailFilters;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionVenteController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    public function __construct(private SiteScopeService $siteScope) {}

    /**
     * Les filtres "select" de DataFilters sont toujours envoyés en tableau
     * (ex: statut[]=impaye), même pour un choix unique : extrait la première
     * valeur pour éviter un "Array to string conversion".
     */
    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return trim(is_array($value) ? (string) reset($value) : (string) $value);
    }

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = $this->scalarInput($request, 'statut');
        $filtrePeriode = $this->scalarInput($request, 'periode');
        if ($filtrePeriode !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $filtrePeriode)) {
            $filtrePeriode = '';
        }

        $isAdmin = $user->isAdmin();
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $query = CommissionPart::query()
            ->from('commission_parts AS cp')
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
            ->where('cv.organization_id', $orgId)
            ->where('cp.type_beneficiaire', 'livreur')
            ->whereNotNull('cp.livreur_id')
            ->where('cp.statut', '!=', StatutCommission::CREEE->value)
            ->leftJoin('livreurs', 'livreurs.id', '=', 'cp.livreur_id')
            ->select(['cp.livreur_id AS beneficiaire_id'])
            ->selectRaw(
                '"livreur"                        AS type_beneficiaire,
                 MAX(cp.beneficiaire_nom)         AS beneficiaire_nom,
                 MAX(livreurs.telephone)          AS telephone,
                 SUM(cp.montant_brut)             AS total_brut_cumule,
                 SUM(cp.frais_supplementaires)    AS total_frais,
                 SUM(cp.montant_net)              AS total_net_cumule,
                 SUM(cp.montant_verse)            AS total_verse,
                 COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes,
                 MIN(CASE WHEN cp.statut IN (?,?) THEN cv.created_at END) AS premiere_echeance',
                [StatutCommission::IMPAYE->value, StatutCommission::PARTIEL->value]
            )
            ->groupBy('cp.livreur_id');

        if ($filtrePeriode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
            $query->whereBetween('cv.created_at', [$debut, $fin]);
        }

        $rows = $query->orderByRaw('SUM(cp.montant_net) - SUM(cp.montant_verse) DESC')->get();

        $allLivreurIds = $rows->pluck('beneficiaire_id')->filter()->unique()->values()->toArray();

        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur($orgId, $allLivreurIds, $filtrePeriode);

        $partsParLivreur = CommissionPart::with([
            'commission.commande.site:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation,capacite_packs,type_vehicule_id,proprietaire_id',
            'commission.vehicule.typeVehicule:id,nom',
            'commission.vehicule.proprietaire:id,prenom,nom,telephone,code_phone_pays',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->whereIn('livreur_id', $allLivreurIds)
            ->when($filtrePeriode !== '', function ($q) use ($filtrePeriode) {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                $q->whereHas('commission', fn ($q2) => $q2->whereBetween('created_at', [$debut, $fin]));
            })
            ->when($isAdmin && ! empty($filtreSiteIds), fn ($q) => $q->whereHas(
                'commission.commande', fn ($q2) => $q2->whereIn('site_id', $filtreSiteIds)
            ))
            ->when(! $isAdmin && ! empty($siteIds), fn ($q) => $q->whereHas(
                'commission.commande', fn ($q2) => $q2->whereIn('site_id', $siteIds)
            ))
            ->get()
            ->groupBy('livreur_id');

        $agencesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.commande.site.nom')
            ->filter()->unique()->sort()->implode(', ')
        );

        $vehiculesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.vehicule')->filter()->unique('id')
            ->map(fn ($v) => [
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'type' => $v->typeVehicule?->nom,
                'capacite_packs' => $v->capacite_packs,
                'proprietaire_nom' => $v->proprietaire
                    ? trim($v->proprietaire->prenom.' '.$v->proprietaire->nom)
                    : null,
                'proprietaire_telephone' => $v->proprietaire?->telephone,
                'proprietaire_code_phone_pays' => $v->proprietaire?->code_phone_pays,
            ])
            ->values()
        );

        $periodesParDate = app(PeriodePaiementService::class)->getPeriodsForDates(
            $orgId,
            TypePeriodePaiement::LIVREUR,
            $rows->pluck('premiere_echeance')
        );
        $labelsParStatut = ['impaye' => 'Impayé', 'partiel' => 'Partiel', 'paye' => 'Payé'];

        $beneficiaires = $rows->map(function ($row) use ($agencesParLivreur, $vehiculesParLivreur, $fraisDepensesParLivreur, $periodesParDate, $labelsParStatut) {
            $livreurId = (string) $row->beneficiaire_id;
            $fraisDepenses = $fraisDepensesParLivreur[$livreurId] ?? 0.0;
            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $row->total_brut_cumule,
                (float) $row->total_frais,
                $fraisDepenses,
                (float) $row->total_verse,
            );

            $periode = null;
            if ($resume['statut'] !== StatutCommission::PAYE->value && $row->premiere_echeance) {
                $periode = $periodesParDate->get(PeriodePaiementService::debutKeyForDate(Carbon::parse($row->premiere_echeance)));
            }
            $statutEffectif = PeriodePayabilityChecker::statutAffichage(
                $periode,
                $resume['statut'],
                $labelsParStatut[$resume['statut']] ?? $resume['statut'],
            );

            return [
                'beneficiaire_id' => $livreurId,
                'beneficiaire_nom' => $row->beneficiaire_nom ?? '—',
                'telephone' => $row->telephone,
                'agence' => $agencesParLivreur->get($livreurId),
                'vehicules' => $vehiculesParLivreur->get($livreurId, collect())->values()->all(),
                'total_brut_cumule' => $resume['brut'],
                'total_frais' => $resume['frais'],
                'total_net_cumule' => $resume['net'],
                'total_verse' => $resume['verse'],
                'solde_restant' => $resume['reste'],
                'nb_commandes' => (int) $row->nb_commandes,
                'statut_global' => $resume['statut'],
                'statut_effectif' => $statutEffectif['status'],
                'statut_effectif_label' => $statutEffectif['label'],
                'payable' => $statutEffectif['payable'],
            ];
        });

        if ($filtreStatut !== '') {
            $beneficiaires = $beneficiaires->filter(fn ($b) => $b['statut_global'] === $filtreStatut);
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => str_contains(mb_strtolower((string) $b['beneficiaire_nom']), $s)
                    || str_contains(preg_replace('/\D/', '', (string) ($b['telephone'] ?? '')), preg_replace('/\D/', '', $search))
            );
        }

        if (! $isAdmin || ! empty($filtreSiteIds)) {
            $allowedIds = $partsParLivreur->keys()->map(fn ($k) => (string) $k)->all();
            $beneficiaires = $beneficiaires->filter(fn ($b) => in_array($b['beneficiaire_id'], $allowedIds));
        }

        $list = $beneficiaires->values();

        $kpis = [
            'nb_livreurs' => $list->count(),
            'total_brut' => (float) $list->sum('total_brut_cumule'),
            'total_net' => (float) $list->sum('total_net_cumule'),
            'total_verse' => (float) $list->sum('total_verse'),
            'solde_total' => (float) $list->sum('solde_restant'),
        ];

        $earliestDate = CommissionPart::query()
            ->from('commission_parts AS cp')
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
            ->where('cv.organization_id', $orgId)
            ->where('cp.type_beneficiaire', 'livreur')
            ->whereNotNull('cp.livreur_id')
            ->min('cv.created_at');

        $periodesDisponibles = $earliestDate
            ? PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate))
            : [];

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();

        return Inertia::render('Comptabilite/CommissionVente/Index', [
            'beneficiaires' => $list,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_site_ids' => $filtreSiteIds,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_courante' => $periodeCourante,
            'sites' => $sites,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function showLivreur(Request $request, string $livreurId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $livreur = Livreur::find($livreurId);
        $nom = $livreur ? trim("{$livreur->prenom} {$livreur->nom}") : '—';

        $allParts = CommissionPart::with(['commission.commande.site', 'commission.vehicule'])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->where('statut', '!=', StatutCommission::CREEE->value)
            ->orderByDesc('commission_vente_id')
            ->get();

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $filters = CommissionDetailFilters::fromRequest($request, $periodeCourante);
        $periodeFilter = $filters['periode'];
        $vehiculeIds = $filters['vehicule_ids'];
        $siteIds = $filters['site_ids'];

        $earliestCommission = $allParts
            ->filter(fn ($p) => $p->commission?->created_at !== null)
            ->sortBy(fn ($p) => $p->commission->created_at)
            ->first();
        $earliestDate = $earliestCommission?->commission?->created_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles(Carbon::instance($earliestDate));

        $vehiculesDisponibles = $allParts
            ->map(fn ($p) => $p->commission?->vehicule)
            ->filter()
            ->unique('id')
            ->sortBy('nom_vehicule')
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
            ])
            ->values();

        $agencesDisponibles = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);

        $filteredParts = $allParts->filter(function ($p) use ($periodeFilter, $vehiculeIds, $siteIds) {
            $commission = $p->commission;

            if ($periodeFilter !== '') {
                $createdAt = $commission?->created_at;
                if (! $createdAt || PeriodeComptableService::codeForLivreur(Carbon::instance($createdAt)) !== $periodeFilter) {
                    return false;
                }
            }

            if (! empty($vehiculeIds) && ! in_array($commission?->vehicule_id, $vehiculeIds, true)) {
                return false;
            }

            if (! empty($siteIds) && ! in_array($commission?->commande?->site_id, $siteIds, true)) {
                return false;
            }

            return true;
        });

        $fraisDepenses = CommissionVenteCalculatorService::fraisDepenseLivreur(
            $orgId,
            $livreurId,
            $periodeFilter !== '' ? $periodeFilter : null,
            $siteIds,
        );
        $resume = CommissionVenteCalculatorService::calculerResume(
            (float) $filteredParts->sum('montant_brut'),
            (float) $filteredParts->sum('frais_supplementaires'),
            $fraisDepenses,
            (float) $filteredParts->sum('montant_verse'),
        );

        $payable = false;
        if ($resume['statut'] !== StatutCommission::PAYE->value) {
            $earliestUnpaidDate = $filteredParts
                ->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->map(fn ($p) => $p->commission?->created_at)
                ->filter()
                ->sort()
                ->first();
            $periode = $earliestUnpaidDate
                ? app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, Carbon::instance($earliestUnpaidDate))
                : null;
            $payable = PeriodePayabilityChecker::statutAffichage($periode, $resume['statut'], '')['payable'];
        }

        $periodeStats = null;
        if ($periodeFilter !== '' && $filteredParts->isNotEmpty()) {
            $netPeriode = (float) $filteredParts->sum('montant_net');
            $versePeriode = (float) $filteredParts->sum('montant_verse');
            $restePeriode = max(0.0, $netPeriode - $versePeriode);
            $periodeStats = [
                'code' => $periodeFilter,
                'label' => PeriodeComptableService::labelForCode($periodeFilter),
                'total_commission' => $netPeriode,
                'total_verse' => $versePeriode,
                'reste' => $restePeriode,
            ];
        }

        $historiqueCommandes = $filteredParts
            ->groupBy('commission_vente_id')
            ->map(function ($partsGroup) {
                $first = $partsGroup->first();
                $commission = $first->commission;
                $periodeCode = $commission->created_at
                    ? PeriodeComptableService::codeForLivreur(Carbon::instance($commission->created_at))
                    : null;

                $montantNet = (float) $partsGroup->sum('montant_net');
                $montantVerse = (float) $partsGroup->sum('montant_verse');

                return [
                    'commission_id' => $commission->id,
                    'reference' => $commission->commande?->reference,
                    'date' => $commission->created_at?->format(self::DATE_FORMAT),
                    'site' => $commission->commande?->site?->nom,
                    'vehicule' => $commission->vehicule ? [
                        'id' => $commission->vehicule->id,
                        'nom' => $commission->vehicule->nom_vehicule,
                        'immatriculation' => $commission->vehicule->immatriculation,
                    ] : null,
                    'montant_brut' => (float) $partsGroup->sum('montant_brut'),
                    'frais' => (float) $partsGroup->sum('frais_supplementaires'),
                    'montant' => $montantNet,
                    'paye' => $montantVerse,
                    'reste' => max(0.0, $montantNet - $montantVerse),
                    'statut' => $first->statut_label,
                    'statut_dot_class' => $first->statut_dot_class,
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                ];
            })
            ->values();

        $historiquePaiementsQuery = PaiementCommissionVente::with('creator')
            ->where('organization_id', $orgId)
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->orderByDesc('paid_at');

        if ($periodeFilter !== '') {
            [$debutPaiement, $finPaiement] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $historiquePaiementsQuery->whereBetween('paid_at', [$debutPaiement->toDateString(), $finPaiement->toDateString().' 23:59:59']);
        }

        $historiquePaiements = $historiquePaiementsQuery
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'paid_at' => $p->paid_at?->format(self::DATE_FORMAT),
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement instanceof ModePaiement
                    ? $p->mode_paiement->label()
                    : (string) $p->mode_paiement,
                'note' => $p->note,
                'created_by' => $p->creator?->name,
            ]);

        $expensesQuery = Depense::with(['user', 'validateur', 'depenseType:id,libelle'])
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreurId)
            ->where('statut', StatutDepense::VALIDE->value);

        if ($periodeFilter !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $expensesQuery->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
        }

        if (! empty($siteIds)) {
            $expensesQuery->whereIn('site_id', $siteIds);
        }

        $expenses = $expensesQuery
            ->orderByDesc('date_depense')
            ->get()
            ->map(fn (Depense $d) => [
                'id' => $d->id,
                'date' => $d->date_depense?->format(self::DATE_FORMAT),
                'type' => $d->depenseType?->libelle ?? '—',
                'commentaire' => $d->commentaire,
                'saisi_par' => $d->user?->name,
                'validateur' => $d->validateur?->name,
                'vehicule' => null,
                'montant' => (float) $d->montant,
            ]);

        $periodeRange = ['debut' => null, 'fin' => null];
        if ($periodeFilter !== '') {
            [$debutRange, $finRange] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $periodeRange = ['debut' => $debutRange->toDateString(), 'fin' => $finRange->toDateString()];
        }

        return Inertia::render('Comptabilite/CommissionVente/Livreur/Show', [
            'livreur' => [
                'id' => $livreurId,
                'nom' => $nom,
                'telephone' => $livreur?->telephone,
            ],
            'commission_summary' => CommissionSummaryFormatter::format(
                $resume['brut'],
                $resume['frais'],
                $resume['net'],
                $resume['verse'],
                $resume['reste'],
            ),
            'commission_details' => $historiqueCommandes,
            'payments' => $historiquePaiements,
            'expenses' => $expenses,
            'modes_paiement' => ModePaiement::options(),
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_stats' => $periodeStats,
            'payable' => $payable,
            'filters' => [
                'periode' => $periodeFilter,
                'vehicule_ids' => $vehiculeIds,
                'site_ids' => $siteIds,
                'periode_range' => $periodeRange,
            ],
            'vehicules_disponibles' => $vehiculesDisponibles,
            'agences_disponibles' => $agencesDisponibles,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payerLivreur(Request $request, string $livreurId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            CommissionVentePaiementService::payer(
                organizationId: auth()->user()->organization_id,
                type: 'livreur',
                beneficiaireId: $livreurId,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        $livreur = Livreur::find($livreurId);
        if ($livreur) {
            $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
            app(AuditLogService::class)->record($livreur, AuditEvent::PAID, auth()->user(), null, null, [
                'module' => 'commissions_vente',
                'montant' => $data['montant'],
                'mode_paiement' => $data['mode_paiement'],
                'description' => "Paiement de {$montantFmt} GNF effectué pour ".trim("{$livreur->prenom} {$livreur->nom}"),
            ]);
        }

        return back()->with('success', 'Paiement enregistré.');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $filtreStatut = $this->scalarInput($request, 'statut');
        $search = trim((string) $request->input('search', ''));
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('livreur_id')->filter()->unique()->values()->all(),
            $filtrePeriode,
            ! empty($filtreSiteIds) ? $filtreSiteIds : null,
        );
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search, $fraisDepensesParLivreur);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-vente-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Total cumulé (GNF)', 'Dépenses (GNF)', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['telephone'] ?? '',
                    self::vehiculesEnTexte($row['vehicules'] ?? []),
                    $row['agence'] ?? '',
                    $periodeLabel,
                    number_format((float) $row['total_cumule'], 0, ',', ' '),
                    number_format((float) $row['frais'], 0, ',', ' '),
                    number_format((float) $row['deja_paye'], 0, ',', ' '),
                    number_format((float) $row['reste'], 0, ',', ' '),
                    $row['statut'],
                    '',
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $filtreStatut = $this->scalarInput($request, 'statut');
        $search = trim((string) $request->input('search', ''));
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('livreur_id')->filter()->unique()->values()->all(),
            $filtrePeriode,
            ! empty($filtreSiteIds) ? $filtreSiteIds : null,
        );
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search, $fraisDepensesParLivreur);
        $siteGroups = $this->buildSiteGroups($rows);

        $org = Organization::find($orgId);
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.index', [
            'title' => 'Commissions livreur vente',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'filters' => ['statut' => $filtreStatut, 'search' => $search],
            'sites' => $siteGroups,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-vente-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @param  array<int, string>  $filtreSiteIds
     */
    private function loadPartsForExport(string $orgId, string $filtrePeriode, array $filtreSiteIds = []): Collection
    {
        $query = CommissionPart::with([
            'commission.commande.site:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation',
            'livreur:id,telephone',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->where('statut', '!=', StatutCommission::CREEE->value);

        if ($filtrePeriode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
            $query->whereHas('commission', fn ($q) => $q->whereBetween('created_at', [$debut, $fin]));
        }

        if (! empty($filtreSiteIds)) {
            $query->whereHas('commission.commande', fn ($q) => $q->whereIn('site_id', $filtreSiteIds));
        }

        return $query->get();
    }

    private function buildExportRows(Collection $parts, string $filtrePeriode, string $filtreStatut, string $search, array $fraisDepensesParLivreur = []): Collection
    {
        $rows = $parts->groupBy('livreur_id')->map(function (Collection $livParts) use ($filtrePeriode, $fraisDepensesParLivreur) {
            $first = $livParts->first();
            $fraisDepenses = $fraisDepensesParLivreur[(string) $first->livreur_id] ?? 0.0;
            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $livParts->sum('montant_brut'),
                (float) $livParts->sum('frais_supplementaires'),
                $fraisDepenses,
                (float) $livParts->sum('montant_verse'),
            );

            $vehicules = $livParts->pluck('commission.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
                ->values();

            $agence = $livParts->pluck('commission.commande.site.nom')
                ->filter()->unique()->sort()->implode(', ');

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $livParts->pluck('commission.created_at')
                    ->filter()
                    ->map(fn ($d) => PeriodeComptableService::labelForCode(
                        PeriodeComptableService::codeForLivreur(Carbon::instance($d))
                    ))
                    ->unique()->implode(', ');

            return [
                'beneficiaire_id' => $first->livreur_id,
                'beneficiaire_nom' => $first->beneficiaire_nom ?? '—',
                'telephone' => $first->livreur?->telephone,
                'vehicules' => $vehicules->all(),
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_cumule' => $resume['brut'],
                'frais' => $resume['frais'],
                'deja_paye' => $resume['verse'],
                'reste' => $resume['reste'],
                'statut' => StatutCommission::from($resume['statut'])->label(),
            ];
        });

        if ($filtreStatut !== '') {
            $statutLabel = match ($filtreStatut) {
                'impaye' => StatutCommission::IMPAYE->label(),
                'paye' => StatutCommission::PAYE->label(),
                'partiel' => StatutCommission::PARTIEL->label(),
                default => null,
            };
            if ($statutLabel !== null) {
                $rows = $rows->filter(fn ($r) => $r['statut'] === $statutLabel);
            }
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['beneficiaire_nom']), $s));
        }

        return $rows->sortBy('beneficiaire_nom')->values();
    }

    private function buildSiteGroups(Collection $rows): array
    {
        $grouped = $rows->groupBy(fn ($r) => $r['agence'] ?? 'Sans agence')
            ->sortKeys()
            ->map(function (Collection $siteRows, string $siteNom) {
                return [
                    'site_nom' => $siteNom === 'Sans agence' ? null : $siteNom,
                    'rows' => $siteRows->values()->toArray(),
                    'totaux' => [
                        'total_cumule' => (float) $siteRows->sum('total_cumule'),
                        'total_frais' => (float) $siteRows->sum('frais'),
                        'total_deja_paye' => (float) $siteRows->sum('deja_paye'),
                        'total_reste' => (float) $siteRows->sum('reste'),
                    ],
                ];
            });

        return $grouped->isEmpty()
            ? [['site_nom' => null, 'rows' => [], 'totaux' => ['total_cumule' => 0, 'total_frais' => 0, 'total_deja_paye' => 0, 'total_reste' => 0]]]
            : $grouped->values()->toArray();
    }

    /** @param  array<int, array{nom: string, immatriculation: ?string}>  $vehicules */
    private static function vehiculesEnTexte(array $vehicules): string
    {
        return implode(' / ', array_map(
            fn ($v) => trim($v['nom'].($v['immatriculation'] ? ' '.$v['immatriculation'] : '')),
            $vehicules
        ));
    }
}
