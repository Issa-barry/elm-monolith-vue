<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Http\Controllers\Controller;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementCommissionVente;
use App\Services\AuditLogService;
use App\Services\CommissionVenteCalculatorService;
use App\Services\CommissionVentePaiementService;
use App\Services\PeriodeComptableService;
use App\Services\SiteScopeService;
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

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = (string) $request->input('statut', '');
        $filtrePeriode = trim((string) $request->input('periode', ''));
        if ($filtrePeriode !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $filtrePeriode)) {
            $filtrePeriode = '';
        }

        $siteProps = $this->siteScope->inertiaProps($user, $orgId, $request->input('site', ''));
        $filtreSite = $siteProps['filtre_site'];
        $isAdmin = $siteProps['is_admin'];
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];

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
                 COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes'
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
            'commission.vehicule:id,nom_vehicule,immatriculation',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->whereIn('livreur_id', $allLivreurIds)
            ->when($filtrePeriode !== '', function ($q) use ($filtrePeriode) {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                $q->whereHas('commission', fn ($q2) => $q2->whereBetween('created_at', [$debut, $fin]));
            })
            ->when($isAdmin && $filtreSite !== '', fn ($q) => $q->whereHas(
                'commission.commande', fn ($q2) => $q2->where('site_id', $filtreSite)
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
            ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
            ->values()
        );

        $beneficiaires = $rows->map(function ($row) use ($agencesParLivreur, $vehiculesParLivreur, $fraisDepensesParLivreur) {
            $livreurId = (string) $row->beneficiaire_id;
            $fraisDepenses = $fraisDepensesParLivreur[$livreurId] ?? 0.0;
            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $row->total_brut_cumule,
                (float) $row->total_frais,
                $fraisDepenses,
                (float) $row->total_verse,
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

        if (! $isAdmin || $filtreSite !== '') {
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
            'filtre_site' => $filtreSite,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_courante' => $periodeCourante,
            'is_admin' => $isAdmin,
            'sites' => $siteProps['sites'],
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

        $fraisDepenses = CommissionVenteCalculatorService::fraisDepenseLivreur($orgId, $livreurId);
        $resume = CommissionVenteCalculatorService::calculerResume(
            (float) $allParts->sum('montant_brut'),
            (float) $allParts->sum('frais_supplementaires'),
            $fraisDepenses,
            (float) $allParts->sum('montant_verse'),
        );

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();

        $earliestCommission = $allParts
            ->filter(fn ($p) => $p->commission?->created_at !== null)
            ->sortBy(fn ($p) => $p->commission->created_at)
            ->first();
        $earliestDate = $earliestCommission?->commission?->created_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles(Carbon::instance($earliestDate));

        $periodeFilter = $request->input('periode', $periodeCourante);

        $filteredParts = $allParts;
        if ($periodeFilter !== '') {
            $filteredParts = $filteredParts->filter(function ($p) use ($periodeFilter) {
                $createdAt = $p->commission?->created_at;
                if (! $createdAt) {
                    return false;
                }

                return PeriodeComptableService::codeForLivreur(Carbon::instance($createdAt)) === $periodeFilter;
            });
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

                return [
                    'commission_id' => $commission->id,
                    'commande_reference' => $commission->commande?->reference,
                    'date_commande' => $commission->created_at?->format(self::DATE_FORMAT),
                    'site' => $commission->commande?->site?->nom,
                    'vehicule' => $commission->vehicule?->nom_vehicule,
                    'montant_brut' => (float) $partsGroup->sum('montant_brut'),
                    'frais' => (float) $partsGroup->sum('frais_supplementaires'),
                    'montant_net' => (float) $partsGroup->sum('montant_net'),
                    'montant_verse' => (float) $partsGroup->sum('montant_verse'),
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                ];
            })
            ->values();

        $historiquePaiements = PaiementCommissionVente::with('creator')
            ->where('organization_id', $orgId)
            ->where('type_beneficiaire', 'livreur')
            ->where('livreur_id', $livreurId)
            ->orderByDesc('paid_at')
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

        $depenses = Depense::with(['user', 'validateur'])
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreurId)
            ->orderByDesc('date_depense')
            ->get()
            ->map(fn (Depense $d) => [
                'id' => $d->id,
                'date_depense' => $d->date_depense?->format(self::DATE_FORMAT),
                'montant' => (float) $d->montant,
                'statut' => $d->statut->value,
                'statut_label' => $d->statut->label(),
                'saisi_par' => $d->user?->name,
                'validateur' => $d->validateur?->name,
                'date_validation' => $d->date_validation?->format(self::DATE_FORMAT),
                'commentaire' => $d->commentaire,
            ]);

        return Inertia::render('Comptabilite/CommissionVente/Livreur/Show', [
            'livreur' => [
                'id' => $livreurId,
                'nom' => $nom,
                'telephone' => $livreur?->telephone,
            ],
            'resume_global' => [
                'total_brut_cumule' => $resume['brut'],
                'total_frais' => $resume['frais'],
                'total_net_cumule' => $resume['net'],
                'total_verse' => $resume['verse'],
                'solde_global' => $resume['reste'],
            ],
            'historique_commandes' => $historiqueCommandes,
            'historique_paiements' => $historiquePaiements,
            'depenses' => $depenses,
            'modes_paiement' => ModePaiement::options(),
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_stats' => $periodeStats,
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

        $orgId = auth()->user()->organization_id;
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreStatut = trim((string) $request->input('statut', ''));
        $search = trim((string) $request->input('search', ''));

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('livreur_id')->filter()->unique()->values()->all(),
            $filtrePeriode
        );
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search, $fraisDepensesParLivreur);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-vente-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Total cumulé (GNF)', 'Frais (GNF)', 'Motif de frais', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['telephone'] ?? '',
                    self::vehiculesEnTexte($row['vehicules'] ?? []),
                    $row['agence'] ?? '',
                    $periodeLabel,
                    number_format((float) $row['total_cumule'], 0, ',', ' '),
                    number_format((float) $row['frais'], 0, ',', ' '),
                    $row['motifs_frais'] ?? '',
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

        $orgId = auth()->user()->organization_id;
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreStatut = trim((string) $request->input('statut', ''));
        $search = trim((string) $request->input('search', ''));

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('livreur_id')->filter()->unique()->values()->all(),
            $filtrePeriode
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

    private function loadPartsForExport(string $orgId, string $filtrePeriode): Collection
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

            $motifs = $livParts->pluck('type_frais')
                ->filter()->unique()
                ->map(fn ($t) => self::labelTypeFrais($t))
                ->implode(', ');

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
                'motifs_frais' => $motifs ?: null,
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

    private static function labelTypeFrais(?string $type): string
    {
        return match ($type) {
            'carburant' => 'Carburant',
            'reparation' => 'Réparation',
            'autre' => 'Autre',
            default => (string) $type,
        };
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
