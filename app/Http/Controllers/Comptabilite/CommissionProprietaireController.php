<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\PaiementCommissionVente;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommissionVentePaiementService;
use App\Services\PeriodeComptableService;
use App\Services\SiteScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionProprietaireController extends Controller
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
            ->where('cp.type_beneficiaire', 'proprietaire')
            ->whereNotNull('cp.proprietaire_id')
            ->leftJoin('proprietaires', 'proprietaires.id', '=', 'cp.proprietaire_id')
            ->select(['cp.proprietaire_id AS beneficiaire_id'])
            ->selectRaw(
                '"proprietaire"                   AS type_beneficiaire,
                 MAX(cp.beneficiaire_nom)         AS beneficiaire_nom,
                 MAX(proprietaires.telephone)     AS telephone,
                 SUM(cp.montant_brut)             AS total_brut_cumule,
                 SUM(cp.montant_verse)            AS total_verse,
                 COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes'
            )
            ->groupBy('cp.proprietaire_id');

        if ($filtrePeriode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
            $query->whereBetween('cv.created_at', [$debut, $fin]);
        }

        $rows = $query->orderByRaw('SUM(cp.montant_brut) - SUM(cp.montant_verse) DESC')->get();

        $proprioIds = $rows->pluck('beneficiaire_id')->filter()->unique()->values();
        $fraisParProprio = [];

        if ($proprioIds->isNotEmpty()) {
            $vehiculesByProprio = Vehicule::whereIn('proprietaire_id', $proprioIds)
                ->where('organization_id', $orgId)
                ->get(['id', 'proprietaire_id'])
                ->groupBy('proprietaire_id');

            $allVehiculeIds = $vehiculesByProprio->flatten()->pluck('id');

            if ($allVehiculeIds->isNotEmpty()) {
                $depQuery = Depense::where('beneficiaire_type', 'vehicule')
                    ->whereIn('beneficiaire_id', $allVehiculeIds)
                    ->where('statut', StatutDepense::VALIDE->value)
                    ->where('organization_id', $orgId);

                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $depQuery->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString()]);
                }

                $fraisParVehicule = $depQuery->get(['beneficiaire_id', 'montant'])->groupBy('beneficiaire_id');

                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $total = 0.0;
                    foreach ($vehicules as $v) {
                        $total += (float) $fraisParVehicule->get($v->id, collect())->sum('montant');
                    }
                    $fraisParProprio[(string) $proprioId] = $total;
                }
            }
        }

        $beneficiaires = $rows->map(function ($row) use ($fraisParProprio) {
            $totalBrut = (float) $row->total_brut_cumule;
            $totalFrais = $fraisParProprio[(string) $row->beneficiaire_id] ?? 0.0;
            $totalNet = max(0.0, $totalBrut - $totalFrais);
            $totalVerse = (float) $row->total_verse;
            $solde = max(0.0, $totalNet - $totalVerse);

            $statutGlobal = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->value,
                $totalVerse > 0 => StatutCommission::PARTIEL->value,
                default => StatutCommission::IMPAYE->value,
            };

            return [
                'beneficiaire_id' => (string) $row->beneficiaire_id,
                'beneficiaire_nom' => $row->beneficiaire_nom ?? '—',
                'telephone' => $row->telephone,
                'total_brut_cumule' => $totalBrut,
                'total_frais' => $totalFrais,
                'total_net_cumule' => $totalNet,
                'total_verse' => $totalVerse,
                'solde_restant' => $solde,
                'nb_commandes' => (int) $row->nb_commandes,
                'statut_global' => $statutGlobal,
            ];
        });

        if ($filtreStatut !== '') {
            $beneficiaires = $beneficiaires->filter(fn ($b) => $b['statut_global'] === $filtreStatut);
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => str_contains(mb_strtolower((string) $b['beneficiaire_nom']), $s)
            );
        }

        $list = $beneficiaires->values();

        $proprioIdsList = $list->pluck('beneficiaire_id')->filter()->unique()->values()->toArray();

        $partsParProprio = CommissionPart::with([
            'commission.commande.site:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id')
            ->whereIn('proprietaire_id', $proprioIdsList)
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
            ->groupBy('proprietaire_id');

        $vehiculesParProprio = $partsParProprio->map(fn ($parts) => $parts
            ->pluck('commission.vehicule')
            ->filter()->unique('id')
            ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
            ->values()->implode(', ')
        );

        $agencesParProprio = $partsParProprio->map(fn ($parts) => $parts
            ->pluck('commission.commande.site.nom')
            ->filter()->unique()->sort()->implode(', ')
        );

        $list = $list->map(function ($b) use ($vehiculesParProprio, $agencesParProprio) {
            return array_merge($b, [
                'vehicules' => $vehiculesParProprio[$b['beneficiaire_id']] ?: null,
                'agence' => $agencesParProprio[$b['beneficiaire_id']] ?: null,
            ]);
        })->values();

        if (! $isAdmin || $filtreSite !== '') {
            $allowedIds = $partsParProprio->keys()->map(fn ($k) => (string) $k)->all();
            $list = $list->filter(fn ($b) => in_array($b['beneficiaire_id'], $allowedIds))->values();
        }

        $kpis = [
            'nb_proprietaires' => $list->count(),
            'total_brut' => (float) $list->sum('total_brut_cumule'),
            'total_net' => (float) $list->sum('total_net_cumule'),
            'total_frais' => (float) $list->sum('total_frais'),
            'total_verse' => (float) $list->sum('total_verse'),
            'solde_total' => (float) $list->sum('solde_restant'),
        ];

        $earliestDate = CommissionPart::query()
            ->from('commission_parts AS cp')
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
            ->where('cv.organization_id', $orgId)
            ->where('cp.type_beneficiaire', 'proprietaire')
            ->whereNotNull('cp.proprietaire_id')
            ->min('cv.created_at');

        $periodesDisponibles = $earliestDate
            ? self::periodesProprietaireBetween(Carbon::parse($earliestDate), now())
            : [];

        $periodeCourante = PeriodeComptableService::periodeCouranteProprietaire();

        return Inertia::render('Comptabilite/CommissionProprietaire/Index', [
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

    public function show(Request $request, string $proprietaireId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $proprio = Proprietaire::find($proprietaireId);
        $nom = $proprio ? trim(($proprio->prenom ?? '').' '.($proprio->nom ?? '')) : '—';

        $allParts = CommissionPart::with(['commission.commande.site', 'commission.vehicule'])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'proprietaire')
            ->where('proprietaire_id', $proprietaireId)
            ->orderByDesc('commission_vente_id')
            ->get();

        $vehiculeIds = Vehicule::where('proprietaire_id', $proprietaireId)
            ->where('organization_id', $orgId)
            ->pluck('id');

        $fraisDepenses = collect();
        $totalFraisDepenses = 0.0;

        if ($vehiculeIds->isNotEmpty()) {
            $fraisDepenses = Depense::with(['depenseType:id,libelle'])
                ->where('beneficiaire_type', 'vehicule')
                ->whereIn('beneficiaire_id', $vehiculeIds)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('organization_id', $orgId)
                ->orderByDesc('date_depense')
                ->get();
            $totalFraisDepenses = (float) $fraisDepenses->sum('montant');
        }

        $totalBrut = (float) $allParts->sum('montant_brut');
        $totalNet = max(0.0, $totalBrut - $totalFraisDepenses);
        $totalVerse = (float) $allParts->sum('montant_verse');
        $solde = max(0.0, $totalNet - $totalVerse);

        $periodeFilter = $request->input('periode', '');
        $periodeCourante = PeriodeComptableService::periodeCouranteProprietaire();

        $earliestCommission = $allParts
            ->filter(fn ($p) => $p->commission?->created_at !== null)
            ->sortBy(fn ($p) => $p->commission->created_at)
            ->first();
        $earliestDate = $earliestCommission?->commission?->created_at ?? now();
        $periodesDisponibles = self::periodesProprietaireBetween(Carbon::instance($earliestDate), now());

        $filteredParts = $allParts;
        if ($periodeFilter !== '') {
            $filteredParts = $filteredParts->filter(function ($p) use ($periodeFilter) {
                $createdAt = $p->commission?->created_at;
                if (! $createdAt) {
                    return false;
                }

                return PeriodeComptableService::codeForProprietaire(Carbon::instance($createdAt)) === $periodeFilter;
            });
        }

        $historiqueCommandes = $filteredParts
            ->groupBy('commission_vente_id')
            ->map(function ($partsGroup) {
                $first = $partsGroup->first();
                $commission = $first->commission;
                $periodeCode = $commission->created_at
                    ? PeriodeComptableService::codeForProprietaire(Carbon::instance($commission->created_at))
                    : null;

                return [
                    'commission_id' => $commission->id,
                    'commande_reference' => $commission->commande?->reference,
                    'date_commande' => $commission->created_at?->format(self::DATE_FORMAT),
                    'site' => $commission->commande?->site?->nom,
                    'vehicule' => $commission->vehicule?->nom_vehicule,
                    'montant_brut' => (float) $partsGroup->sum('montant_brut'),
                    'montant_net' => (float) $partsGroup->sum('montant_net'),
                    'montant_verse' => (float) $partsGroup->sum('montant_verse'),
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                ];
            })
            ->values();

        $historiquePaiements = PaiementCommissionVente::with('creator')
            ->where('organization_id', $orgId)
            ->where('type_beneficiaire', 'proprietaire')
            ->where('proprietaire_id', $proprietaireId)
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

        return Inertia::render('Comptabilite/CommissionProprietaire/Show', [
            'proprietaire' => [
                'id' => $proprietaireId,
                'nom' => $nom,
                'telephone' => $proprio?->telephone,
            ],
            'resume_global' => [
                'total_brut_cumule' => $totalBrut,
                'total_frais_depenses' => $totalFraisDepenses,
                'total_net_cumule' => $totalNet,
                'total_verse' => $totalVerse,
                'solde_global' => $solde,
            ],
            'frais_depenses' => $fraisDepenses->map(fn ($d) => [
                'id' => $d->id,
                'date' => $d->date_depense->toDateString(),
                'type' => $d->depenseType?->libelle ?? '—',
                'montant' => (float) $d->montant,
                'commentaire' => $d->commentaire,
            ])->values(),
            'historique_commandes' => $historiqueCommandes,
            'historique_paiements' => $historiquePaiements,
            'modes_paiement' => ModePaiement::options(),
            'periode_courante' => $periodeCourante,
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payer(Request $request, string $proprietaireId): RedirectResponse
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
                type: 'proprietaire',
                beneficiaireId: $proprietaireId,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        $proprietaire = Proprietaire::find($proprietaireId);
        if ($proprietaire) {
            $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
            app(AuditLogService::class)->record($proprietaire, AuditEvent::PAID, auth()->user(), null, null, [
                'module' => 'commissions_proprietaires',
                'montant' => $data['montant'],
                'mode_paiement' => $data['mode_paiement'],
                'description' => "Paiement de {$montantFmt} GNF effectué pour ".trim("{$proprietaire->prenom} {$proprietaire->nom}"),
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

        [$parts, $fraisParProprio, $motifsParProprio] = $this->loadPartsForExport($orgId, $filtrePeriode);
        $rows = $this->buildExportRows($parts, $fraisParProprio, $motifsParProprio, $filtrePeriode, $filtreStatut, $search);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-proprietaires-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Total cumulé (GNF)', 'Frais (GNF)', 'Motif de frais', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['telephone'] ?? '',
                    $row['vehicules'] ?? '',
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

        [$parts, $fraisParProprio, $motifsParProprio] = $this->loadPartsForExport($orgId, $filtrePeriode);
        $rows = $this->buildExportRows($parts, $fraisParProprio, $motifsParProprio, $filtrePeriode, $filtreStatut, $search);
        $siteGroups = $this->buildSiteGroups($rows);

        $org = Organization::find($orgId);
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.index', [
            'title' => 'Commissions propriétaire',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'filters' => ['statut' => $filtreStatut, 'search' => $search],
            'sites' => $siteGroups,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-proprietaires-'.now()->format('Y-m-d').'.pdf');
    }

    private function loadPartsForExport(string $orgId, string $filtrePeriode): array
    {
        $query = CommissionPart::with([
            'commission.commande.site:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'proprietaire')
            ->whereNotNull('proprietaire_id');

        if ($filtrePeriode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
            $query->whereHas('commission', fn ($q) => $q->whereBetween('created_at', [$debut, $fin]));
        }

        $parts = $query->get();

        $proprioIds = $parts->pluck('proprietaire_id')->filter()->unique();
        $fraisParProprio = [];
        $motifsParProprio = [];

        if ($proprioIds->isNotEmpty()) {
            $vehiculesByProprio = Vehicule::whereIn('proprietaire_id', $proprioIds)
                ->where('organization_id', $orgId)
                ->get(['id', 'proprietaire_id', 'nom_vehicule', 'immatriculation'])
                ->groupBy('proprietaire_id');

            $allVehiculeIds = $vehiculesByProprio->flatten()->pluck('id');

            if ($allVehiculeIds->isNotEmpty()) {
                $depQuery = Depense::with('depenseType:id,libelle')
                    ->where('beneficiaire_type', 'vehicule')
                    ->whereIn('beneficiaire_id', $allVehiculeIds)
                    ->where('statut', StatutDepense::VALIDE->value)
                    ->where('organization_id', $orgId);

                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $depQuery->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString()]);
                }

                $depenses = $depQuery->get()->groupBy('beneficiaire_id');

                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $proprioDeps = $vehicules->flatMap(fn ($v) => $depenses->get($v->id, collect()));
                    $fraisParProprio[(string) $proprioId] = (float) $proprioDeps->sum('montant');
                    $motifsParProprio[(string) $proprioId] = $proprioDeps
                        ->pluck('depenseType.libelle')->filter()->unique()->implode(', ');
                }

                // Build vehicules map per proprietaire
                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $vehiculesByProprio[(string) $proprioId] = $vehicules
                        ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
                        ->implode(', ');
                }
            }
        }

        return [$parts, $fraisParProprio, $motifsParProprio];
    }

    private function buildExportRows(Collection $parts, array $fraisParProprio, array $motifsParProprio, string $filtrePeriode, string $filtreStatut, string $search): Collection
    {
        $rows = $parts->groupBy('proprietaire_id')->map(function (Collection $propParts, string $proprioId) use ($fraisParProprio, $motifsParProprio, $filtrePeriode) {
            $first = $propParts->first();
            $totalBrut = (float) $propParts->sum('montant_brut');
            $totalFrais = $fraisParProprio[$proprioId] ?? 0.0;
            $totalNet = max(0.0, $totalBrut - $totalFrais);
            $totalVerse = (float) $propParts->sum('montant_verse');
            $solde = max(0.0, $totalNet - $totalVerse);

            $vehicules = $propParts->pluck('commission.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
                ->implode(', ');

            $agence = $propParts->pluck('commission.commande.site.nom')
                ->filter()->unique()->sort()->implode(', ');

            $motifs = $motifsParProprio[$proprioId] ?? null;

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $propParts->pluck('commission.created_at')
                    ->filter()
                    ->map(fn ($d) => PeriodeComptableService::labelForCode(
                        PeriodeComptableService::codeForProprietaire(Carbon::instance($d))
                    ))
                    ->unique()->implode(', ');

            $statut = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->label(),
                $totalVerse > 0 => StatutCommission::PARTIEL->label(),
                default => StatutCommission::IMPAYE->label(),
            };

            return [
                'beneficiaire_id' => $proprioId,
                'beneficiaire_nom' => $first->beneficiaire_nom ?? '—',
                'telephone' => $first->telephone ?? null,
                'vehicules' => $vehicules ?: null,
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_cumule' => $totalNet,
                'frais' => $totalFrais,
                'motifs_frais' => $motifs ?: null,
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

    private static function periodesProprietaireBetween(Carbon $from, Carbon $to): array
    {
        $periodes = [];
        $cursor = $from->copy()->startOfMonth();
        $limit = $to->copy()->startOfMonth();

        while ($cursor->lte($limit)) {
            $code = $cursor->format('Y-m').'-M';
            $periodes[] = [
                'code' => $code,
                'label' => PeriodeComptableService::labelForCode($code),
            ];
            $cursor->addMonth();
        }

        return array_reverse($periodes);
    }
}
