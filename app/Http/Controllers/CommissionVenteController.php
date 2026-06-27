<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\PaiementCommissionVente;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\PeriodeComptableService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    private const DATETIME_DISPLAY_FORMAT = 'd/m/Y H:i';

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $tab = $request->input('tab', 'livreurs');
        $periodeDefault = $tab === 'proprietaires' ? 'month' : 'week';
        $periode = $request->input('periode', $periodeDefault);
        $typeBeneficiaire = $tab === 'proprietaires' ? 'proprietaire' : 'livreur';

        $query = CommissionPart::query()
            ->from('commission_parts AS cp')
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
            ->where('cv.organization_id', $orgId)
            ->where('cp.type_beneficiaire', $typeBeneficiaire);

        match ($periode) {
            'today' => $query->whereDate('cv.created_at', now()),
            'week' => $query->whereBetween('cv.created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereYear('cv.created_at', now()->year)
                ->whereMonth('cv.created_at', now()->month),
            default => null,
        };

        if ($tab === 'livreurs') {
            $query
                ->whereNotNull('cp.livreur_id')
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
                     MAX(cv.created_at)               AS date_derniere_commande'
                )
                ->groupBy('cp.livreur_id');
        } else {
            $query
                ->whereNotNull('cp.proprietaire_id')
                ->leftJoin('proprietaires', 'proprietaires.id', '=', 'cp.proprietaire_id')
                ->select(['cp.proprietaire_id AS beneficiaire_id'])
                ->selectRaw(
                    '"proprietaire"                   AS type_beneficiaire,
                     MAX(cp.beneficiaire_nom)         AS beneficiaire_nom,
                     MAX(proprietaires.telephone)     AS telephone,
                     SUM(cp.montant_brut)             AS total_brut_cumule,
                     SUM(cp.frais_supplementaires)    AS total_frais,
                     SUM(cp.montant_net)              AS total_net_cumule,
                     SUM(cp.montant_verse)            AS total_verse,
                     COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes,
                     MAX(cv.created_at)               AS date_derniere_commande'
                )
                ->groupBy('cp.proprietaire_id');
        }

        $rows = $query->orderByRaw('SUM(cp.montant_net) - SUM(cp.montant_verse) DESC')->get();

        // ── Frais depuis Dépenses (propriétaires uniquement) ──────────────────
        $fraisParProprio = [];
        if ($tab === 'proprietaires') {
            $proprioIds = $rows->pluck('beneficiaire_id')->filter()->unique()->values();

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

                match ($periode) {
                    'today' => $depQuery->whereDate('date_depense', now()),
                    'week' => $depQuery->whereBetween('date_depense', [now()->startOfWeek(), now()->endOfWeek()]),
                    'month' => $depQuery->whereYear('date_depense', now()->year)
                        ->whereMonth('date_depense', now()->month),
                    default => null,
                };

                $fraisParVehicule = $depQuery->get(['beneficiaire_id', 'montant'])->groupBy('beneficiaire_id');

                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $total = 0.0;
                    foreach ($vehicules as $v) {
                        $total += (float) $fraisParVehicule->get($v->id, collect())->sum('montant');
                    }
                    $fraisParProprio[$proprioId] = $total;
                }
            }
        }

        // Build vehicule lookup per beneficiaire for search
        $beneficiaireIds = $rows->pluck('beneficiaire_id')->filter()->unique()->values();
        $vehiculesParBeneficiaire = collect();

        if ($tab === 'livreurs' && $beneficiaireIds->isNotEmpty()) {
            $vehiculesParBeneficiaire = CommissionPart::query()
                ->from('commission_parts AS cp')
                ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
                ->join('vehicules AS v', 'v.id', '=', 'cv.vehicule_id')
                ->where('cv.organization_id', $orgId)
                ->where('cp.type_beneficiaire', 'livreur')
                ->whereIn('cp.livreur_id', $beneficiaireIds)
                ->select('cp.livreur_id AS bid', 'v.nom_vehicule', 'v.immatriculation')
                ->distinct()
                ->get()
                ->groupBy('bid')
                ->map(fn ($vs) => $vs->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))->implode(' '));
        } elseif ($tab === 'proprietaires' && $beneficiaireIds->isNotEmpty()) {
            $vehiculesParBeneficiaire = Vehicule::whereIn('proprietaire_id', $beneficiaireIds)
                ->where('organization_id', $orgId)
                ->get(['proprietaire_id', 'nom_vehicule', 'immatriculation'])
                ->groupBy('proprietaire_id')
                ->map(fn ($vs) => $vs->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))->implode(' '));
        }

        $beneficiaires = $rows->map(function ($row) use ($tab, $fraisParProprio, $vehiculesParBeneficiaire) {
            $totalBrut = (float) $row->total_brut_cumule;
            $totalFrais = $tab === 'proprietaires'
                ? ($fraisParProprio[$row->beneficiaire_id] ?? 0.0)
                : (float) $row->total_frais;
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
                'type_beneficiaire' => $row->type_beneficiaire,
                'beneficiaire_nom' => $row->beneficiaire_nom ?? '—',
                'telephone' => $row->telephone,
                'vehicules' => $vehiculesParBeneficiaire[(string) $row->beneficiaire_id] ?? null,
                'total_brut_cumule' => $totalBrut,
                'total_frais' => $totalFrais,
                'total_net_cumule' => $totalNet,
                'total_verse' => $totalVerse,
                'solde_restant' => $solde,
                'nb_commandes' => (int) $row->nb_commandes,
                'date_derniere_commande' => $row->date_derniere_commande
                    ? Carbon::parse($row->date_derniere_commande)->format(self::DATE_DISPLAY_FORMAT)
                    : null,
                'statut_global' => $statutGlobal,
            ];
        });

        $filtreStatut = $request->input('statut', '');
        $search = $request->input('search', '');

        if ($filtreStatut) {
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => $b['statut_global'] === $filtreStatut
            );
        }

        if ($search) {
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => $this->beneficiaireMatchesSearch($b, $search)
            );
        }

        $list = $beneficiaires->values();

        $totaux = [
            'nb_beneficiaires' => $list->count(),
            'total_brut' => (float) $list->sum('total_brut_cumule'),
            'total_verse' => (float) $list->sum('total_verse'),
            'solde_total' => (float) $list->sum('solde_restant'),
            'nb_impaye' => $list->where('statut_global', StatutCommission::IMPAYE->value)->count(),
            'nb_partiel' => $list->where('statut_global', StatutCommission::PARTIEL->value)->count(),
            'nb_paye' => $list->where('statut_global', StatutCommission::PAYE->value)->count(),
        ];

        return Inertia::render('Commissions/Index', [
            'beneficiaires' => $list,
            'totaux' => $totaux,
            'periode' => $periode,
            'tab' => $tab,
            'filtre_statut' => $filtreStatut,
            'search' => $search,
            'statuts' => StatutCommission::options(),
            'can_payer' => auth()->user()->can('viewAny', CommandeVente::class),
        ]);
    }

    /**
     * GET /commissions/beneficiaires/{type}/{beneficiaireId}
     */
    public function showBeneficiaire(Request $request, string $type, string $beneficiaireId): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422);

        $orgId = auth()->user()->organization_id;

        if ($type === 'livreur') {
            $model = Livreur::find($beneficiaireId);
            $nom = $model ? trim("{$model->prenom} {$model->nom}") : '—';
            $telephone = $model?->telephone;
        } else {
            $model = Proprietaire::find($beneficiaireId);
            $nom = $model ? trim(($model->prenom ?? '').' '.($model->nom ?? '')) : '—';
            $telephone = $model?->telephone;
        }

        $baseQuery = CommissionPart::with(['commission.commande.site', 'commission.vehicule'])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', $type);

        if ($type === 'livreur') {
            $baseQuery->where('livreur_id', $beneficiaireId);
        } else {
            $baseQuery->where('proprietaire_id', $beneficiaireId);
        }

        $allParts = $baseQuery->orderByDesc('commission_vente_id')->get();

        // ── Frais depuis Dépenses (propriétaires uniquement) ─────────────────
        $fraisDepenses = collect();
        $totalFraisDepenses = 0.0;
        if ($type === 'proprietaire') {
            $vehiculeIds = Vehicule::where('proprietaire_id', $beneficiaireId)
                ->where('organization_id', $orgId)
                ->pluck('id');

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
        }

        $totalBrut = (float) $allParts->sum('montant_brut');
        $totalFrais = $type === 'proprietaire'
            ? $totalFraisDepenses
            : (float) $allParts->sum('frais_supplementaires');
        $totalNet = max(0.0, $totalBrut - $totalFrais);
        $totalVerse = (float) $allParts->sum('montant_verse');

        // Solde payable = parts activées seulement (IMPAYE/PARTIEL, pas CREEE).
        $activeParts = $allParts->filter(fn ($p) => $p->statut !== StatutCommission::CREEE);
        $solde = $type === 'proprietaire'
            ? max(0.0, (float) $activeParts->sum('montant_brut') - $totalFraisDepenses - (float) $activeParts->sum('montant_verse'))
            : max(0.0, (float) $activeParts->sum('montant_net') - (float) $activeParts->sum('montant_verse'));

        $statutGlobal = match (true) {
            $solde <= 0 && $totalVerse > 0 => 'solde',
            $totalVerse > 0 => 'partielle',
            default => 'a_verser',
        };

        $resumeGlobal = [
            'id' => $beneficiaireId,
            'type' => $type,
            'nom' => $nom,
            'telephone' => $telephone,
            'nb_commandes' => $allParts->groupBy('commission_vente_id')->count(),
            'total_brut_cumule' => $totalBrut,
            'total_frais' => $totalFrais,
            'total_net_cumule' => $totalNet,
            'total_verse' => $totalVerse,
            'solde_global' => $solde,
            'statut_global' => $statutGlobal,
        ];

        $periodeCourante = $type === 'livreur'
            ? PeriodeComptableService::periodeCouranteLivreur()
            : PeriodeComptableService::periodeCouranteProprietaire();

        $earliestCommission = $allParts
            ->filter(fn ($p) => $p->commission?->created_at !== null)
            ->sortBy(fn ($p) => $p->commission->created_at)
            ->first();
        $earliestDate = $earliestCommission?->commission?->created_at ?? now();
        $periodesDisponibles = $type === 'livreur'
            ? PeriodeComptableService::periodesDisponibles(Carbon::instance($earliestDate))
            : [];

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $commandeSearch = $request->input('commande');
        $periodeFilter = $request->input('periode', $type === 'livreur' ? $periodeCourante : '');

        $filteredParts = $allParts;

        if ($dateFrom) {
            $from = Carbon::parse($dateFrom)->startOfDay();
            $filteredParts = $filteredParts->filter(fn ($p) => $p->commission?->created_at?->gte($from));
        }
        if ($dateTo) {
            $to = Carbon::parse($dateTo)->endOfDay();
            $filteredParts = $filteredParts->filter(fn ($p) => $p->commission?->created_at?->lte($to));
        }
        if ($commandeSearch) {
            $q = mb_strtolower($commandeSearch);
            $filteredParts = $filteredParts->filter(
                fn ($p) => str_contains(mb_strtolower($p->commission?->commande?->reference ?? ''), $q)
            );
        }
        if ($periodeFilter !== '' && $type === 'livreur') {
            $filteredParts = $filteredParts->filter(function ($p) use ($periodeFilter) {
                $createdAt = $p->commission?->created_at;
                if (! $createdAt) {
                    return false;
                }

                return PeriodeComptableService::codeForLivreur(Carbon::instance($createdAt)) === $periodeFilter;
            });
        }

        $historiqueCommandes = $filteredParts
            ->groupBy('commission_vente_id')
            ->map(function ($partsGroup) use ($type) {
                $first = $partsGroup->first();
                $commission = $first->commission;
                $periodeCode = $commission->created_at
                    ? PeriodeComptableService::codeFor($type, Carbon::instance($commission->created_at))
                    : null;

                return [
                    'commission_id' => $commission->id,
                    'commande_reference' => $commission->commande?->reference,
                    'commande_id' => $commission->commande_vente_id,
                    'date_commande' => $commission->created_at?->format(self::DATE_DISPLAY_FORMAT),
                    'site' => $commission->commande?->site?->nom,
                    'vehicule' => $commission->vehicule?->nom_vehicule,
                    'immatriculation' => $commission->vehicule?->immatriculation,
                    'taux' => (float) $first->taux_commission,
                    'montant_brut' => (float) $partsGroup->sum('montant_brut'),
                    'frais' => (float) $partsGroup->sum('frais_supplementaires'),
                    'montant_net' => (float) $partsGroup->sum('montant_net'),
                    'montant_verse' => (float) $partsGroup->sum('montant_verse'),
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                    'part_id' => $first->id,
                    'type_frais' => $first->type_frais,
                    'commentaire_frais' => $first->commentaire_frais,
                ];
            })
            ->values();

        $paiementsQuery = PaiementCommissionVente::with('creator')
            ->where('organization_id', $orgId)
            ->where('type_beneficiaire', $type);

        if ($type === 'livreur') {
            $paiementsQuery->where('livreur_id', $beneficiaireId);
        } else {
            $paiementsQuery->where('proprietaire_id', $beneficiaireId);
        }

        $historiquePaiements = $paiementsQuery
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'paid_at' => $p->paid_at?->format(self::DATE_DISPLAY_FORMAT),
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement instanceof ModePaiement
                    ? $p->mode_paiement->label()
                    : (string) $p->mode_paiement,
                'note' => $p->note,
                'created_by' => $p->creator?->name,
            ]);

        return Inertia::render('Commissions/Beneficiaire/Show', [
            'resume_global' => $resumeGlobal,
            'historique_commandes' => $historiqueCommandes,
            'historique_paiements_globaux' => $historiquePaiements,
            'frais_depenses' => $fraisDepenses->map(fn ($d) => [
                'id' => $d->id,
                'date' => $d->date_depense->toDateString(),
                'type' => $d->depenseType?->libelle ?? '—',
                'vehicule' => $d->vehicule?->nom_vehicule,
                'montant' => (float) $d->montant,
                'commentaire' => $d->commentaire,
            ])->values(),
            'modes_paiement' => ModePaiement::options(),
            'filtres' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'commande' => $commandeSearch,
                'periode' => $periodeFilter,
            ],
            'selected_periode' => $periodeFilter,
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'periodes_disponibles' => $periodesDisponibles,
        ]);
    }

    public function show(CommissionVente $commission_vente): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        abort_unless(
            $commission_vente->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $commission_vente->load([
            'commande.site',
            'vehicule.equipe',
            'vehicule.proprietaire',
            'vehicule.frais',
            'parts.versements.creator',
            'parts.proprietaire:id,telephone',
            'parts.livreur:id,telephone',
        ]);

        return Inertia::render('Commissions/Show', [
            'commission' => $this->mapCommission($commission_vente, withParts: true),
            'modes_paiement' => ModePaiement::options(),
        ]);
    }

    private function beneficiaireMatchesSearch(array $b, string $search): bool
    {
        $s = trim($search);

        // Name
        if (str_contains(mb_strtolower((string) $b['beneficiaire_nom']), mb_strtolower($s))) {
            return true;
        }

        // Vehicle (nom + immatriculation string)
        if ($b['vehicules'] && str_contains(mb_strtolower((string) $b['vehicules']), mb_strtolower($s))) {
            return true;
        }

        // Phone: strip non-digits, require >= 6 digits
        $digits = preg_replace('/\D/', '', $s);
        if (strlen($digits) >= 6) {
            $telDigits = preg_replace('/\D/', '', (string) ($b['telephone'] ?? ''));
            if ($telDigits !== '' && str_contains($telDigits, $digits)) {
                return true;
            }
        }

        // Amount: strip GNF/spaces/commas → if purely numeric → check amounts
        $amountStr = preg_replace('/[\s,]+/', '', str_ireplace('gnf', '', $s));
        if ($amountStr !== '' && ctype_digit($amountStr)) {
            foreach (['total_net_cumule', 'total_verse', 'solde_restant', 'total_brut_cumule'] as $field) {
                if (str_contains((string) (int) round((float) $b[$field]), $amountStr)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mapCommission(CommissionVente $c, bool $withParts = false): array
    {
        $data = [
            'id' => $c->id,
            'commande_id' => $c->commande_vente_id,
            'commande_reference' => $c->commande?->reference,
            'site_nom' => $c->commande?->site?->nom,
            'vehicule_nom' => $c->vehicule?->nom_vehicule,
            'immatriculation' => $c->vehicule?->immatriculation,
            'equipe_nom' => $c->vehicule?->nom_vehicule,
            'proprietaire_nom' => $c->vehicule?->proprietaire
                ? trim(($c->vehicule->proprietaire->prenom ?? '').' '.($c->vehicule->proprietaire->nom ?? ''))
                : null,
            'vehicule_frais_total' => $c->vehicule?->relationLoaded('frais')
                ? (float) $c->vehicule->frais->sum('montant')
                : 0.0,
            'montant_commande' => (float) $c->montant_commande,
            'montant_commission_totale' => (float) $c->montant_commission_totale,
            'montant_verse' => (float) $c->montant_verse,
            'montant_restant' => (float) $c->montant_restant,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'is_paye' => $c->isPaye(),
            'is_payable' => $c->commande?->isEncaissable() ?? false,
            'created_at' => $c->created_at?->format(self::DATE_DISPLAY_FORMAT),
            'nb_parts' => $c->relationLoaded('parts') ? $c->parts->count() : null,
        ];

        if ($withParts) {
            $parts = $c->relationLoaded('parts') ? $c->parts : $c->load('parts.versements.creator')->parts;

            $data['parts'] = $parts->map(fn (CommissionPart $p) => [
                'id' => $p->id,
                'type_beneficiaire' => $p->type_beneficiaire,
                'beneficiaire_nom' => $p->beneficiaire_nom,
                'beneficiaire_telephone' => $p->proprietaire?->telephone ?? $p->livreur?->telephone,
                'role' => $p->role,
                'taux_commission' => (float) $p->taux_commission,
                'montant_brut' => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'type_frais' => $p->type_frais,
                'commentaire_frais' => $p->commentaire_frais,
                'montant_net' => (float) $p->montant_net,
                'montant_verse' => (float) $p->montant_verse,
                'montant_restant' => (float) $p->montant_restant,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'is_paye' => $p->isPaye(),
                'versements' => $p->versements
                    ->sortByDesc(fn ($v) => $v->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($v) => [
                        'id' => $v->id,
                        'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
                        'enregistre_le' => $v->created_at?->format(self::DATETIME_DISPLAY_FORMAT),
                        'mode_paiement' => $v->mode_paiement instanceof ModePaiement
                            ? $v->mode_paiement->label()
                            : (string) $v->mode_paiement,
                        'montant' => (float) $v->montant,
                        'note' => $v->note,
                        'created_by' => $v->creator?->name,
                    ])
                    ->all(),
            ])->values()->all();
        }

        return $data;
    }
}
