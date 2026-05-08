<?php

namespace App\Http\Controllers;

use App\Enums\StatutCommission;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\Livreur;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeComptableService;
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
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $orgId        = auth()->user()->organization_id;
        $search       = trim((string) $request->input('search', ''));
        $filtreStatut = (string) $request->input('statut', '');

        $rows = CommissionPaymentService::soldesParLivreur($orgId);

        // Fetch telephone + vehicule data for ALL livreurs upfront (needed for search)
        $allLivreurIds = $rows->pluck('livreur_id')->filter()->unique()->values()->toArray();

        $telephones = Livreur::whereIn('id', $allLivreurIds)->pluck('telephone', 'id');

        $vehiculesParLivreur = CommissionLogistiquePart::with('commission.vehicule:id,nom_vehicule,immatriculation')
            ->whereIn('livreur_id', $allLivreurIds)
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
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

        // Build complete list with all searchable fields
        $livreurs = $rows->map(fn ($row) => [
            'livreur_id' => $row->livreur_id,
            'nom'        => $row->beneficiaire_nom,
            'telephone'  => $telephones[$row->livreur_id] ?? null,
            'vehicules'  => $vehiculesParLivreur[$row->livreur_id] ?? null,
            'impaye'     => (float) $row->impaye,
            'paye'       => (float) $row->paye,
        ]);

        if ($filtreStatut !== '') {
            $livreurs = match ($filtreStatut) {
                'impaye' => $livreurs->filter(fn ($r) => $r['impaye'] > 0),
                'paye'   => $livreurs->filter(fn ($r) => $r['paye'] > 0 && $r['impaye'] <= 0),
                default  => $livreurs,
            };
        }

        if ($search !== '') {
            $livreurs = $livreurs->filter(fn ($l) => $this->livreurMatchesSearch($l, $search));
        }

        $list = $livreurs->values();

        $kpis = [
            'nb_livreurs'   => $list->count(),
            'total_impaye'  => (float) collect($list)->sum('impaye'),
            'total_paye'    => (float) collect($list)->sum('paye'),
        ];

        return Inertia::render('Logistique/Commissions/Index', [
            'livreurs'      => $list,
            'kpis'          => $kpis,
            'search'        => $search,
            'filtre_statut' => $filtreStatut,
            'statuts'       => StatutCommission::options(),
            'can_payer'     => auth()->user()->can('logistique.commission.verser'),
        ]);
    }

    private function livreurMatchesSearch(array $l, string $search): bool
    {
        $s = trim($search);

        // Name
        if (str_contains(mb_strtolower((string) $l['nom']), mb_strtolower($s))) {
            return true;
        }

        // Vehicle (nom + immatriculation string)
        if ($l['vehicules'] && str_contains(mb_strtolower((string) $l['vehicules']), mb_strtolower($s))) {
            return true;
        }

        // Phone: strip non-digits, require >= 6 digits
        $digits = preg_replace('/\D/', '', $s);
        if (strlen($digits) >= 6) {
            $telDigits = preg_replace('/\D/', '', (string) ($l['telephone'] ?? ''));
            if ($telDigits !== '' && str_contains($telDigits, $digits)) {
                return true;
            }
        }

        // Amount: strip GNF/spaces/commas → if purely numeric → check amounts
        $amountStr = preg_replace('/[\s,]+/', '', str_ireplace('gnf', '', $s));
        if ($amountStr !== '' && ctype_digit($amountStr)) {
            $total = (int) round($l['impaye'] + $l['paye']);
            foreach ([(int) round($l['impaye']), (int) round($l['paye']), $total] as $amt) {
                if (str_contains((string) $amt, $amountStr)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ── Show livreur : cumul global + relevé par transfert ───────────────────

    /**
     * GET /logistique/commissions/livreurs/{livreurId}
     */
    public function showLivreur(Request $request, string $livreurId): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $orgId   = auth()->user()->organization_id;
        $allParts = CommissionPaymentService::releveLivreur($livreurId, $orgId);

        $livreurNom       = $allParts->first()?->beneficiaire_nom ?? '—';
        $livreurTelephone = Livreur::find($livreurId)?->telephone;

        // ── KPIs globaux ──────────────────────────────────────────────────────
        $totalImpaye = (float) $allParts
            ->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
            ->sum('montant_restant');

        $totalPaye = (float) $allParts
            ->filter(fn ($p) => $p->statut === StatutCommission::PAYE)
            ->sum('montant_net');

        // ── Périodes disponibles ───────────────────────────────────────────────
        $earliestPart  = $allParts->whereNotNull('periode')->sortBy('earned_at')->first();
        $earliestDate  = $earliestPart?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles($earliestDate);

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $selectedPeriode = $request->input('periode', '');

        $filteredParts = $selectedPeriode !== ''
            ? $allParts->filter(fn ($p) => $p->periode === $selectedPeriode)
            : $allParts;

        // ── Statistiques de la période sélectionnée ───────────────────────────
        $periodeStats = null;

        if ($selectedPeriode !== '' && $filteredParts->isNotEmpty()) {
            $totalCommissionPeriode = (float) $filteredParts->sum('montant_net');
            $totalVersePeriode = (float) $filteredParts
                ->flatMap(fn ($p) => $p->paymentItems)
                ->sum('amount_allocated');
            $restePeriode = max(0.0, $totalCommissionPeriode - $totalVersePeriode);

            [$statutVal, $statutLabel, $statutDot] = match (true) {
                $totalVersePeriode <= 0      => [StatutCommission::IMPAYE->value,  'Impayé',  StatutCommission::IMPAYE->dotClass()],
                $restePeriode < 0.01         => [StatutCommission::PAYE->value,    'Payé',    StatutCommission::PAYE->dotClass()],
                default                      => [StatutCommission::PARTIEL->value, 'Partiel', StatutCommission::PARTIEL->dotClass()],
            };

            $periodeStats = [
                'code'             => $selectedPeriode,
                'label'            => PeriodeComptableService::labelForCode($selectedPeriode),
                'total_commission' => $totalCommissionPeriode,
                'total_verse'      => $totalVersePeriode,
                'reste'            => $restePeriode,
                'statut'           => $statutVal,
                'statut_label'     => $statutLabel,
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
            'id'           => $p->id,
            'montant'      => (float) $p->montant,
            'mode_paiement'=> $p->mode_paiement,
            'note'         => $p->note,
            'paid_at'      => $p->paid_at?->format(self::DATE_FORMAT),
            'created_by'   => $p->createur
                ? trim("{$p->createur->prenom} {$p->createur->nom}")
                : null,
        ]);

        return Inertia::render('Logistique/Commissions/Livreur/Show', [
            'livreur'    => [
                'id'        => $livreurId,
                'nom'       => $livreurNom,
                'telephone' => $livreurTelephone,
            ],
            'kpis' => [
                'impaye' => $totalImpaye,
                'paye'   => $totalPaye,
            ],
            'parts' => $filteredParts->map(fn ($p) => [
                'id'                => $p->id,
                'transfert_reference'=> $p->commission?->transfert?->reference,
                'montant_net'       => (float) $p->montant_net,
                'earned_at'         => $p->earned_at?->format(self::DATE_FORMAT),
                'periode'           => $p->periode,
                'periode_label'     => $p->periode ? PeriodeComptableService::labelForCode($p->periode) : null,
                'statut'            => $p->statut?->value,
                'statut_label'      => $p->statut_label,
                'statut_dot_class'  => $p->statut_dot_class,
            ])->values(),
            'periode_stats'        => $periodeStats,
            'payments'             => $payments,
            'periode_courante'     => $periodeCourante,
            'periode_courante_label'=> PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode'     => $selectedPeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'modes_paiement'       => [
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
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);

        $soldes  = CommissionPaymentService::soldesParVehicule($vehicule);
        $payments = \App\Models\CommissionPayment::with('createur:id,prenom,nom')
            ->where('vehicule_id', $vehicule->id)
            ->where('organization_id', $vehicule->organization_id)
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn ($p) => [
                'id'               => $p->id,
                'beneficiary_type' => $p->beneficiary_type,
                'beneficiary_nom'  => $p->beneficiary_nom,
                'montant'          => (float) $p->montant,
                'mode_paiement'    => $p->mode_paiement,
                'note'             => $p->note,
                'paid_at'          => $p->paid_at?->format(self::DATE_FORMAT),
                'created_by'       => $p->createur ? trim("{$p->createur->prenom} {$p->createur->nom}") : null,
            ]);

        return Inertia::render('Logistique/Commissions/Vehicule/Show', [
            'vehicule' => [
                'id'             => $vehicule->id,
                'nom'            => $vehicule->nom_vehicule,
                'immatriculation'=> $vehicule->immatriculation,
            ],
            'livreurs'       => $soldes['livreurs'],
            'proprietaires'  => $soldes['proprietaires'],
            'payments'       => $payments,
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('verserCommission', $vehicule->commissions()->first()
                ? $vehicule->commissions()->first()->transfert
                : new \App\Models\TransfertLogistique),
        ]);
    }

    // ── Relevé détaillé d'un bénéficiaire ────────────────────────────────────

    /**
     * GET /logistique/commissions/vehicules/{vehicule}/beneficiaires/{type}/{id}
     */
    public function releve(Request $request, Vehicule $vehicule, string $type, string $beneficiaireId): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);
        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422);

        $parts = CommissionPaymentService::releve($vehicule, $type, $beneficiaireId);
        $nom   = $parts->first()?->beneficiaire_nom ?? '—';

        return Inertia::render('Logistique/Commissions/Beneficiaire/Show', [
            'vehicule' => [
                'id'             => $vehicule->id,
                'nom'            => $vehicule->nom_vehicule,
                'immatriculation'=> $vehicule->immatriculation,
            ],
            'beneficiaire' => [
                'id'   => $beneficiaireId,
                'type' => $type,
                'nom'  => $nom,
            ],
            'parts' => $parts->map(fn ($p) => [
                'id'                    => $p->id,
                'transfert_reference'   => $p->commission?->transfert?->reference,
                'taux_commission'       => (float) $p->taux_commission,
                'montant_brut'          => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net'           => (float) $p->montant_net,
                'montant_verse'         => (float) $p->montant_verse,
                'montant_restant'       => (float) $p->montant_restant,
                'earned_at'             => $p->earned_at?->format(self::DATE_FORMAT),
                'statut'                => $p->statut?->value,
                'statut_label'          => $p->statut_label,
                'statut_dot_class'      => $p->statut_dot_class,
                'payments'              => $p->paymentItems->map(fn ($item) => [
                    'paid_at'      => $item->payment?->paid_at?->format(self::DATE_FORMAT),
                    'montant'      => (float) $item->amount_allocated,
                    'mode_paiement'=> $item->payment?->mode_paiement,
                ])->values()->all(),
            ])->values(),
        ]);
    }
}
