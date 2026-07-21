<?php

namespace App\Http\Controllers;

use App\Enums\StatutCommission;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\Livreur;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\CommissionAdjustmentService;
use App\Services\CommissionPaymentService;
use App\Services\CommissionSearchService;
use App\Services\CommissionStatusResolver;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVehiculeController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    // ── Index : liste des livreurs avec commissions cumulées ─────────────────

    /**
     * GET /logistique/commissions
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = (string) $request->input('statut', '');
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreSite = trim((string) $request->input('site', ''));

        $rows = CommissionPaymentService::soldesParLivreur(
            $orgId,
            $filtrePeriode !== '' ? $filtrePeriode : null,
            $filtreSite !== '' ? $filtreSite : null
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

        // Fetch telephone + vehicule data for ALL livreurs upfront (needed for search)
        $allLivreurIds = $rows->pluck('livreur_id')->filter()->unique()->values()->toArray();

        $telephones = Livreur::whereIn('id', $allLivreurIds)->pluck('telephone', 'id');

        $vehiculesParLivreur = CommissionLogistiquePart::with('commission.vehicule:id,nom_vehicule,immatriculation')
            ->whereIn('livreur_id', $allLivreurIds)
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($filtrePeriode !== '', fn ($q) => $q->where('periode', $filtrePeriode))
            ->when($filtreSite !== '', fn ($q) => $q->whereHas('commission.transfert', fn ($t) => $t->where('site_source_id', $filtreSite)->orWhere('site_destination_id', $filtreSite)))
            ->get()
            ->groupBy('livreur_id')
            ->map(fn ($parts) => $parts
                ->pluck('commission.vehicule')
                ->filter()
                ->unique('id')
                ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
                ->values()
                ->implode(' ')
            );

        $periodesParDate = app(PeriodePaiementService::class)->getPeriodsForDates(
            $orgId,
            TypePeriodePaiement::LIVREUR,
            $rows->pluck('premiere_echeance')
        );

        $teamStatusParPeriode = $periodesParDate->mapWithKeys(
            fn ($periode) => [$periode->id => CommissionAdjustmentService::statutValidationParBeneficiaire($periode)]
        );

        // Build complete list with all searchable fields
        $livreurs = $rows->map(function ($row) use ($telephones, $vehiculesParLivreur, $periodesParDate, $teamStatusParPeriode) {
            $impaye = (float) $row->impaye;
            $paye = (float) $row->paye;

            $periode = $row->premiere_echeance
                ? $periodesParDate->get(PeriodePaiementService::debutKeyForDate(Carbon::parse($row->premiere_echeance)))
                : null;
            $teamStatus = $periode ? ($teamStatusParPeriode[$periode->id]["livreur:{$row->livreur_id}"] ?? null) : null;

            $paymentValue = $paye > 0.009 && $impaye <= 0.009 ? StatutCommission::PAYE->value : StatutCommission::IMPAYE->value;
            $paymentLabel = $paymentValue === StatutCommission::PAYE->value ? 'Payé' : 'Impayé';
            $resolved = CommissionStatusResolver::resolve($periode, $teamStatus, $paymentValue, $paymentLabel);

            return [
                'livreur_id' => $row->livreur_id,
                'nom' => $row->beneficiaire_nom,
                'telephone' => $telephones[$row->livreur_id] ?? null,
                'vehicules' => $vehiculesParLivreur[$row->livreur_id] ?? null,
                'impaye' => $impaye,
                'paye' => $paye,
                'remaining_amount' => $impaye,
                ...$resolved,
            ];
        });

        if ($filtreStatut !== '') {
            $livreurs = match ($filtreStatut) {
                'impaye' => $livreurs->filter(fn ($r) => $r['impaye'] > 0),
                'paye' => $livreurs->filter(fn ($r) => $r['paye'] > 0 && $r['impaye'] <= 0),
                default => $livreurs,
            };
        }

        $livreurs = CommissionSearchService::filter($livreurs, $search);

        $list = $livreurs->values();

        $kpis = [
            'nb_livreurs' => $list->count(),
            'total_impaye' => (float) collect($list)->sum('impaye'),
            'total_paye' => (float) collect($list)->sum('paye'),
        ];

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        $dateAffichee = $filtrePeriode !== ''
            ? PeriodeComptableService::dateRangeForCode($filtrePeriode)[0]
            : now();
        $periodeAffichee = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, $dateAffichee);

        return Inertia::render('Logistique/Commissions/Index', [
            'livreurs' => $list,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_site' => $filtreSite,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_affichee' => $periodeAffichee ? [
                'id' => $periodeAffichee->id,
                'reference' => $periodeAffichee->reference,
                'statut' => $periodeAffichee->statut->value,
                'statut_label' => $periodeAffichee->statut_label,
            ] : null,
            'sites' => $sites->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])->values(),
            'statuts' => StatutCommission::options(),
            'can_payer' => auth()->user()->can('logistique.commission.verser'),
        ]);
    }

    // ── Show livreur : cumul global + relevé par transfert ───────────────────

    /**
     * GET /logistique/commissions/livreurs/{livreurId}
     */
    public function showLivreur(Request $request, string $livreurId): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;
        $allParts = CommissionPaymentService::releveLivreur($livreurId, $orgId);

        $livreurNom = $allParts->first()?->beneficiaire_nom ?? '—';
        $livreurTelephone = Livreur::find($livreurId)?->telephone;

        // ── KPIs globaux ──────────────────────────────────────────────────────
        $impayeParts = $allParts->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true));
        $totalImpaye = (float) $impayeParts->sum('montant_restant');

        if ($totalImpaye > 0.009) {
            $earliestUnpaidDate = $impayeParts->min('earned_at');
            $periodeResolue = $earliestUnpaidDate
                ? app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, Carbon::parse($earliestUnpaidDate))
                : null;
        } else {
            $periodeResolue = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, now());
        }

        $teamStatus = $periodeResolue
            ? (CommissionAdjustmentService::statutValidationParBeneficiaire($periodeResolue)["livreur:{$livreurId}"] ?? null)
            : null;

        $paymentValue = $totalImpaye > 0.009 ? StatutCommission::IMPAYE->value : StatutCommission::PAYE->value;
        $paymentLabel = $totalImpaye > 0.009 ? 'Impayé' : 'Payé';
        $statutCommission = CommissionStatusResolver::resolve($periodeResolue, $teamStatus, $paymentValue, $paymentLabel);
        $payable = $statutCommission['can_pay'];

        $totalPaye = (float) $allParts
            ->filter(fn ($p) => $p->statut === StatutCommission::PAYE)
            ->sum('montant_a_payer');

        // ── Périodes disponibles ───────────────────────────────────────────────
        $earliestPart = $allParts->whereNotNull('periode')->sortBy('earned_at')->first();
        $earliestDate = $earliestPart?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles($earliestDate);

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $selectedPeriode = $request->input('periode', '');

        $filteredParts = $selectedPeriode !== ''
            ? $allParts->filter(fn ($p) => $p->periode === $selectedPeriode)
            : $allParts;

        // ── Statistiques de la période sélectionnée ───────────────────────────
        $periodeStats = null;

        if ($selectedPeriode !== '' && $filteredParts->isNotEmpty()) {
            $totalCommissionPeriode = (float) $filteredParts->sum('montant_a_payer');
            $totalVersePeriode = (float) $filteredParts
                ->flatMap(fn ($p) => $p->paymentItems)
                ->sum('amount_allocated');
            $restePeriode = max(0.0, $totalCommissionPeriode - $totalVersePeriode);

            [$statutVal, $statutLabel, $statutDot] = match (true) {
                $totalVersePeriode <= 0 => [StatutCommission::IMPAYE->value,  'Impayé',  StatutCommission::IMPAYE->dotClass()],
                $restePeriode < 0.01 => [StatutCommission::PAYE->value,    'Payé',    StatutCommission::PAYE->dotClass()],
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

        // ── Historique des paiements ───────────────────────────────────────────
        $filteredPartIds = $filteredParts->pluck('id')->toArray();

        $paymentsQuery = CommissionPayment::with('createur:id,prenom,nom')
            ->where('organization_id', $orgId)
            ->where('livreur_id', $livreurId)
            ->where('beneficiary_type', 'livreur')
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($selectedPeriode !== '') {
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
            'created_by' => $p->createur
                ? trim("{$p->createur->prenom} {$p->createur->nom}")
                : null,
        ]);

        return Inertia::render('Logistique/Commissions/Livreur/Show', [
            'livreur' => [
                'id' => $livreurId,
                'nom' => $livreurNom,
                'telephone' => $livreurTelephone,
            ],
            'kpis' => [
                'impaye' => $totalImpaye,
                'paye' => $totalPaye,
            ],
            'payable' => $payable,
            'statut_commission' => $statutCommission,
            'periode_affichee' => $periodeResolue ? [
                'id' => $periodeResolue->id,
                'reference' => $periodeResolue->reference,
                'statut' => $periodeResolue->statut->value,
                'statut_label' => $periodeResolue->statut_label,
            ] : null,
            'parts' => $filteredParts->map(fn ($p) => [
                'id' => $p->id,
                'transfert_reference' => $p->commission?->transfert?->reference,
                'montant_net' => (float) $p->montant_net,
                'montant_a_payer' => $p->montant_a_payer,
                'earned_at' => $p->earned_at?->format(self::DATE_FORMAT),
                'periode' => $p->periode,
                'periode_label' => $p->periode ? PeriodeComptableService::labelForCode($p->periode) : null,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
            ])->values(),
            'periode_stats' => $periodeStats,
            'payments' => $payments,
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $selectedPeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('logistique.commission.verser'),
        ]);
    }

    // ── Show : détail par véhicule ────────────────────────────────────────────

    /**
     * GET /logistique/commissions/vehicules/{vehicule}
     */
    public function show(Request $request, Vehicule $vehicule): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);

        $soldes = CommissionPaymentService::soldesParVehicule($vehicule);
        $payments = CommissionPayment::with('createur:id,prenom,nom')
            ->where('vehicule_id', $vehicule->id)
            ->where('organization_id', $vehicule->organization_id)
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'beneficiary_type' => $p->beneficiary_type,
                'beneficiary_nom' => $p->beneficiary_nom,
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement,
                'note' => $p->note,
                'paid_at' => $p->paid_at?->format(self::DATE_FORMAT),
                'created_by' => $p->createur ? trim("{$p->createur->prenom} {$p->createur->nom}") : null,
            ]);

        return Inertia::render('Logistique/Commissions/Vehicule/Show', [
            'vehicule' => [
                'id' => $vehicule->id,
                'nom' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ],
            'livreurs' => $soldes['livreurs'],
            'proprietaires' => $soldes['proprietaires'],
            'payments' => $payments,
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('verserCommission', $vehicule->commissions()->first()
                ? $vehicule->commissions()->first()->transfert
                : new TransfertLogistique),
        ]);
    }

    // ── Relevé détaillé d'un bénéficiaire ────────────────────────────────────

    /**
     * GET /logistique/commissions/vehicules/{vehicule}/beneficiaires/{type}/{id}
     */
    public function releve(Request $request, Vehicule $vehicule, string $type, string $beneficiaireId): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);
        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422);

        $parts = CommissionPaymentService::releve($vehicule, $type, $beneficiaireId);
        $nom = $parts->first()?->beneficiaire_nom ?? '—';

        return Inertia::render('Logistique/Commissions/Beneficiaire/Show', [
            'vehicule' => [
                'id' => $vehicule->id,
                'nom' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ],
            'beneficiaire' => [
                'id' => $beneficiaireId,
                'type' => $type,
                'nom' => $nom,
            ],
            'parts' => $parts->map(fn ($p) => [
                'id' => $p->id,
                'transfert_reference' => $p->commission?->transfert?->reference,
                'taux_commission' => (float) $p->taux_commission,
                'montant_brut' => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net' => (float) $p->montant_net,
                'montant_a_payer' => $p->montant_a_payer,
                'montant_verse' => (float) $p->montant_verse,
                'montant_restant' => (float) $p->montant_restant,
                'earned_at' => $p->earned_at?->format(self::DATE_FORMAT),
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
                'payments' => $p->paymentItems->map(fn ($item) => [
                    'paid_at' => $item->payment?->paid_at?->format(self::DATE_FORMAT),
                    'montant' => (float) $item->amount_allocated,
                    'mode_paiement' => $item->payment?->mode_paiement,
                ])->values()->all(),
            ])->values(),
        ]);
    }
}
