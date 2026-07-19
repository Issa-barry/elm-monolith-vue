<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\CommissionPaymentService;
use App\Services\CommissionSearchService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use App\Services\PeriodePayabilityChecker;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionDetailFilters;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionLogistiqueController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    private const MODES_PAIEMENT = ['especes', 'virement', 'cheque', 'mobile_money'];

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

        $rows = CommissionPaymentService::soldesParLivreur(
            $orgId,
            $filtrePeriode !== '' ? $filtrePeriode : null,
            ! empty($filtreSiteIds) ? $filtreSiteIds : null
        );

        $periodesDisponibles = CommissionLogistiquePart::query()
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->whereNotNull('periode')
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->select('periode')
            ->distinct()
            ->orderByDesc('periode')
            ->pluck('periode')
            ->map(fn (string $code) => [
                'code' => $code,
                'label' => PeriodeComptableService::labelForCode($code),
            ])
            ->values();

        $allLivreurIds = $rows->pluck('livreur_id')->filter()->unique()->values()->toArray();
        $telephones = Livreur::whereIn('id', $allLivreurIds)->pluck('telephone', 'id');

        $fraisDepensesParLivreur = [];
        if (! empty($allLivreurIds)) {
            $depQuery = Depense::where('beneficiaire_type', 'livreur')
                ->whereIn('beneficiaire_id', $allLivreurIds)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('organization_id', $orgId);
            if ($filtrePeriode !== '') {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                $depQuery->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
            }
            $fraisDepensesParLivreur = $depQuery->get(['beneficiaire_id', 'montant'])
                ->groupBy('beneficiaire_id')
                ->map(fn ($d) => (float) $d->sum('montant'))
                ->toArray();
        }

        $partsParLivreur = CommissionLogistiquePart::with([
            'commission.vehicule:id,nom_vehicule,immatriculation,capacite_packs,type_vehicule_id,proprietaire_id',
            'commission.vehicule.typeVehicule:id,nom',
            'commission.vehicule.proprietaire:id,prenom,nom,telephone,code_phone_pays',
            'commission.transfert.siteSource:id,nom',
        ])
            ->whereIn('livreur_id', $allLivreurIds)
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($filtrePeriode !== '', fn ($q) => $q->where('periode', $filtrePeriode))
            ->when($isAdmin && ! empty($filtreSiteIds), fn ($q) => $q->whereHas(
                'commission.transfert',
                fn ($t) => $t->whereIn('site_source_id', $filtreSiteIds)->orWhereIn('site_destination_id', $filtreSiteIds)
            ))
            ->when(! $isAdmin && ! empty($siteIds), fn ($q) => $q->whereHas(
                'commission.transfert',
                fn ($t) => $t->whereIn('site_source_id', $siteIds)->orWhereIn('site_destination_id', $siteIds)
            ))
            ->get()
            ->groupBy('livreur_id');

        $vehiculesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.vehicule')
            ->filter()->unique('id')
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

        $agencesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.transfert.siteSource.nom')
            ->filter()->unique()->sort()->implode(', ')
        );

        $periodesParDate = app(PeriodePaiementService::class)->getPeriodsForDates(
            $orgId,
            TypePeriodePaiement::LIVREUR,
            $rows->pluck('premiere_echeance')
        );

        $livreurs = $rows->map(function ($row) use ($telephones, $vehiculesParLivreur, $agencesParLivreur, $fraisDepensesParLivreur, $periodesParDate) {
            $frais = $fraisDepensesParLivreur[(string) $row->livreur_id] ?? 0.0;
            $impaye = max(0.0, (float) $row->impaye - $frais);
            $paye = (float) $row->paye;

            $statutEffectif = null;
            if ($impaye > 0.009) {
                $periode = $row->premiere_echeance
                    ? $periodesParDate->get(PeriodePaiementService::debutKeyForDate(Carbon::parse($row->premiere_echeance)))
                    : null;
                $statutEffectif = PeriodePayabilityChecker::statutAffichage($periode, StatutCommission::IMPAYE->value, 'Impayé');
            } elseif ($paye > 0.009) {
                $statutEffectif = PeriodePayabilityChecker::statutAffichage(null, StatutCommission::PAYE->value, 'Payé');
            }

            return [
                'livreur_id' => $row->livreur_id,
                'nom' => $row->beneficiaire_nom,
                'telephone' => $telephones[$row->livreur_id] ?? null,
                'vehicules' => $vehiculesParLivreur[$row->livreur_id]?->values()->all() ?? [],
                'agence' => $agencesParLivreur[$row->livreur_id] ?? null,
                'frais_depenses' => $frais,
                'impaye' => $impaye,
                'paye' => $paye,
                'statut_effectif' => $statutEffectif['status'] ?? null,
                'statut_effectif_label' => $statutEffectif['label'] ?? null,
                'payable' => $statutEffectif['payable'] ?? false,
            ];
        });

        if (! $isAdmin) {
            $allowedIds = $partsParLivreur->keys()->all();
            $livreurs = $livreurs->filter(fn ($r) => in_array($r['livreur_id'], $allowedIds));
        }

        if ($filtreStatut !== '') {
            $livreurs = match ($filtreStatut) {
                'impaye' => $livreurs->filter(fn ($r) => $r['impaye'] > 0),
                'paye' => $livreurs->filter(fn ($r) => $r['paye'] > 0 && $r['impaye'] <= 0),
                default => $livreurs,
            };
        }

        $livreurs = CommissionSearchService::filter($livreurs, $search)->values();

        $kpis = [
            'nb_livreurs' => $livreurs->count(),
            'total_impaye' => (float) $livreurs->sum('impaye'),
            'total_paye' => (float) $livreurs->sum('paye'),
        ];

        return Inertia::render('Comptabilite/CommissionLogistique/Index', [
            'livreurs' => $livreurs,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_site_ids' => $filtreSiteIds,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'sites' => $sites,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function showLivreur(Request $request, string $livreurId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $allParts = CommissionPaymentService::releveLivreur($livreurId, $orgId);

        $livreurNom = $allParts->first()?->beneficiaire_nom ?? '—';
        $livreurTelephone = Livreur::find($livreurId)?->telephone;

        $earliestPart = $allParts->whereNotNull('periode')->sortBy('earned_at')->first();
        $earliestDate = $earliestPart?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles($earliestDate);

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $filters = CommissionDetailFilters::fromRequest($request);
        $selectedPeriode = $filters['periode'];
        $vehiculeIds = $filters['vehicule_ids'];
        $siteIds = $filters['site_ids'];

        $vehiculesDisponibles = $allParts
            ->map(fn ($p) => $p->commission?->transfert?->vehicule)
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

        $filteredParts = $allParts->filter(function ($p) use ($selectedPeriode, $vehiculeIds, $siteIds) {
            if ($selectedPeriode !== '' && $p->periode !== $selectedPeriode) {
                return false;
            }

            $transfert = $p->commission?->transfert;

            if (! empty($vehiculeIds) && ! in_array($transfert?->vehicule_id, $vehiculeIds, true)) {
                return false;
            }

            if (! empty($siteIds) && ! in_array($transfert?->site_source_id, $siteIds, true) && ! in_array($transfert?->site_destination_id, $siteIds, true)) {
                return false;
            }

            return true;
        });

        $totalBrut = (float) $filteredParts->sum('montant_brut');
        $totalFrais = (float) $filteredParts->sum('frais_supplementaires');
        $totalNet = (float) $filteredParts->sum('montant_net');
        $totalVerse = (float) $filteredParts->sum('montant_verse');

        $impayeParts = $filteredParts->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true));
        $totalImpaye = (float) $impayeParts->sum('montant_restant');

        $payable = false;
        if ($totalImpaye > 0.009) {
            $earliestUnpaidDate = $impayeParts->min('earned_at');
            $periode = $earliestUnpaidDate
                ? app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, Carbon::parse($earliestUnpaidDate))
                : null;
            $payable = PeriodePayabilityChecker::statutAffichage($periode, StatutCommission::IMPAYE->value, 'Impayé')['payable'];
        }

        $periodeStats = null;

        if ($selectedPeriode !== '' && $filteredParts->isNotEmpty()) {
            $totalCommissionPeriode = (float) $filteredParts->sum('montant_net');
            $totalVersePeriode = (float) $filteredParts
                ->flatMap(fn ($p) => $p->paymentItems)
                ->sum('amount_allocated');
            $restePeriode = max(0.0, $totalCommissionPeriode - $totalVersePeriode);

            [$statutVal, $statutLabel, $statutDot] = match (true) {
                $totalVersePeriode <= 0 => [StatutCommission::IMPAYE->value, 'Impayé', StatutCommission::IMPAYE->dotClass()],
                $restePeriode < 0.01 => [StatutCommission::PAYE->value, 'Payé', StatutCommission::PAYE->dotClass()],
                default => [StatutCommission::PARTIEL->value, 'Partiel', StatutCommission::PARTIEL->dotClass()],
            };

            $periodeStats = [
                'code' => $selectedPeriode,
                'label' => PeriodeComptableService::labelForCode($selectedPeriode),
                'total_commission' => $totalCommissionPeriode,
                'total_verse' => $totalVersePeriode,
                'reste' => $restePeriode,
                'statut' => $statutVal,
                'statut_label' => $statutLabel,
                'statut_dot_class' => $statutDot,
            ];
        }

        $filteredPartIds = $filteredParts->pluck('id')->toArray();

        $paymentsQuery = CommissionPayment::with('createur:id,prenom,nom')
            ->where('organization_id', $orgId)
            ->where('livreur_id', $livreurId)
            ->where('beneficiary_type', 'livreur')
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($selectedPeriode !== '' || ! empty($vehiculeIds) || ! empty($siteIds)) {
            count($filteredPartIds) > 0
                ? $paymentsQuery->whereHas('items', fn ($q) => $q->whereIn('part_id', $filteredPartIds))
                : $paymentsQuery->whereRaw('1 = 0');
        }

        $payments = $paymentsQuery->get()->map(fn ($p) => [
            'id' => $p->id,
            'montant' => (float) $p->montant,
            'mode_paiement' => $p->mode_paiement,
            'note' => $p->note,
            'paid_at' => $p->paid_at?->format(self::DATE_FORMAT),
            'created_by' => $p->createur ? trim("{$p->createur->prenom} {$p->createur->nom}") : null,
        ]);

        $expensesQuery = Depense::with(['user', 'validateur', 'depenseType:id,libelle'])
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreurId)
            ->where('statut', StatutDepense::VALIDE->value);

        if ($selectedPeriode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($selectedPeriode);
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
        if ($selectedPeriode !== '') {
            [$debutRange, $finRange] = PeriodeComptableService::dateRangeForCode($selectedPeriode);
            $periodeRange = ['debut' => $debutRange->toDateString(), 'fin' => $finRange->toDateString()];
        }

        return Inertia::render('Comptabilite/CommissionLogistique/Livreur/Show', [
            'livreur' => [
                'id' => $livreurId,
                'nom' => $livreurNom,
                'telephone' => $livreurTelephone,
            ],
            'commission_summary' => CommissionSummaryFormatter::format(
                $totalBrut,
                $totalFrais,
                $totalNet,
                $totalVerse,
                $totalImpaye,
            ),
            'payable' => $payable,
            'commission_details' => $filteredParts->map(function ($p) {
                $transfert = $p->commission?->transfert;
                $vehicule = $transfert?->vehicule;

                return [
                    'id' => $p->id,
                    'reference' => $transfert?->reference,
                    'site' => $transfert?->siteDestination?->nom ?? $transfert?->siteSource?->nom,
                    'vehicule' => $vehicule ? [
                        'id' => $vehicule->id,
                        'nom' => $vehicule->nom_vehicule,
                        'immatriculation' => $vehicule->immatriculation,
                    ] : null,
                    'montant' => (float) $p->montant_net,
                    'paye' => (float) $p->montant_verse,
                    'reste' => (float) $p->montant_restant,
                    'date' => $p->earned_at?->format(self::DATE_FORMAT),
                    'periode' => $p->periode,
                    'periode_label' => $p->periode ? PeriodeComptableService::labelForCode($p->periode) : null,
                    'statut' => $p->statut_label,
                    'statut_dot_class' => $p->statut_dot_class,
                ];
            })->values(),
            'periode_stats' => $periodeStats,
            'payments' => $payments,
            'expenses' => $expenses,
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $selectedPeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'filters' => [
                'periode' => $selectedPeriode,
                'vehicule_ids' => $vehiculeIds,
                'site_ids' => $siteIds,
                'periode_range' => $periodeRange,
            ],
            'vehicules_disponibles' => $vehiculesDisponibles,
            'agences_disponibles' => $agencesDisponibles,
            'modes_paiement' => [
                ['value' => 'especes', 'label' => 'Espèces'],
                ['value' => 'virement', 'label' => 'Virement'],
                ['value' => 'cheque', 'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payerLivreur(Request $request, string $livreurId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1'],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            CommissionPaymentService::payerLivreur(
                livreurId: $livreurId,
                orgId: auth()->user()->organization_id,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        $livreur = Livreur::find($livreurId);
        if ($livreur) {
            $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
            app(AuditLogService::class)->record($livreur, AuditEvent::PAID, auth()->user(), null, null, [
                'module' => 'commissions_logistique',
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
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $filtreStatut = $this->scalarInput($request, 'statut');
        $search = trim((string) $request->input('search', ''));
        $isAdmin = $user->isAdmin();
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds);
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-logistique-'.now()->format('Y-m-d').'.csv';

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
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $filtreStatut = $this->scalarInput($request, 'statut');
        $search = trim((string) $request->input('search', ''));
        $isAdmin = $user->isAdmin();
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds);
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search);
        $siteGroups = $this->buildSiteGroups($rows);

        $org = Organization::find($orgId);
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.index', [
            'title' => 'Commissions livreur logistique',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'filters' => ['statut' => $filtreStatut, 'search' => $search],
            'sites' => $siteGroups,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-logistique-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @param  array<int, string>  $filtreSiteIds
     */
    private function loadPartsForExport(string $orgId, string $filtrePeriode, array $filtreSiteIds): Collection
    {
        return CommissionLogistiquePart::with([
            'commission.transfert.siteSource:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation',
            'livreur:id,telephone',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($filtrePeriode !== '', fn ($q) => $q->where('periode', $filtrePeriode))
            ->when(! empty($filtreSiteIds), fn ($q) => $q->whereHas(
                'commission.transfert',
                fn ($t) => $t->whereIn('site_source_id', $filtreSiteIds)->orWhereIn('site_destination_id', $filtreSiteIds)
            ))
            ->get();
    }

    private function buildExportRows(Collection $parts, string $filtrePeriode, string $filtreStatut, string $search): Collection
    {
        $rows = $parts->groupBy('livreur_id')->map(function (Collection $livParts) use ($filtrePeriode) {
            $first = $livParts->first();
            $totalBrut = (float) $livParts->sum('montant_brut');
            $totalFrais = (float) $livParts->sum('frais_supplementaires');
            $totalNet = (float) $livParts->sum('montant_net');
            $totalVerse = (float) $livParts->sum('montant_verse');
            $solde = max(0.0, $totalNet - $totalVerse);

            $vehicules = $livParts->pluck('commission.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
                ->values();

            $agence = $livParts->pluck('commission.transfert.siteSource.nom')
                ->filter()->unique()->sort()->implode(', ');

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $livParts->pluck('periode')->filter()->unique()
                    ->map(fn ($c) => PeriodeComptableService::labelForCode($c))
                    ->implode(', ');

            $statut = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->label(),
                $totalVerse > 0 => StatutCommission::PARTIEL->label(),
                default => StatutCommission::IMPAYE->label(),
            };

            return [
                'beneficiaire_id' => $first->livreur_id,
                'beneficiaire_nom' => $first->beneficiaire_nom ?? '—',
                'telephone' => $first->livreur?->telephone,
                'vehicules' => $vehicules->all(),
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_cumule' => $totalBrut,
                'frais' => $totalFrais,
                'deja_paye' => $totalVerse,
                'reste' => $solde,
                'statut' => $statut,
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
