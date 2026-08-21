<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\PaiementFichePaiement;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommissionAdjustmentService;
use App\Services\CommissionStatusResolver;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionDetailFilters;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionProprietaireController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    public function __construct(private SiteScopeService $siteScope) {}

    /**
     * Écran "Commission propriétaire" — cf. CommissionVenteController::index()
     * pour le raisonnement (collection plutôt que SQL brut, can_pay/can_payer
     * toujours false : paiement exclusivement via Fiches de paiement).
     */
    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filtreNom = trim((string) $request->input('nom', ''));
        $filtreTelephone = trim((string) $request->input('telephone', ''));
        $filtreStatut = (string) $request->input('statut', '');
        $filtrePeriode = trim((string) $request->input('periode', ''));
        if ($filtrePeriode !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $filtrePeriode)) {
            $filtrePeriode = '';
        }

        $isAdmin = $user->isAdmin();
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $query = CommissionEnveloppePart::with([
            'enveloppe.source.site:id,nom',
            'enveloppe.source.vehicule:id,nom_vehicule,immatriculation',
        ])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });

        if ($isAdmin && ! empty($filtreSiteIds)) {
            $query->whereHas('enveloppe.source', fn ($q) => $q->whereIn('site_id', $filtreSiteIds));
        } elseif (! $isAdmin && ! empty($siteIds)) {
            $query->whereHas('enveloppe.source', fn ($q) => $q->whereIn('site_id', $siteIds));
        }

        $allParts = $query->get();
        $partsParProprio = $allParts->groupBy('beneficiaire_id');

        $proprioIds = $partsParProprio->keys()->map(fn ($id) => (string) $id)->values();
        $fraisParProprio = [];

        if ($proprioIds->isNotEmpty()) {
            $vehiculesByProprio = Vehicule::whereIn('proprietaire_id', $proprioIds)
                ->where('organization_id', $orgId)
                ->get(['id', 'proprietaire_id'])
                ->groupBy('proprietaire_id');

            $allVehiculeIds = $vehiculesByProprio->flatten()->pluck('id');

            $applyPeriode = function ($query) use ($filtrePeriode) {
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $query->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
                }

                return $query;
            };

            if ($allVehiculeIds->isNotEmpty()) {
                $depQuery = Depense::where('beneficiaire_type', 'vehicule')
                    ->whereIn('beneficiaire_id', $allVehiculeIds)
                    ->where('statut', StatutDepense::VALIDE->value)
                    ->where('organization_id', $orgId);
                $applyPeriode($depQuery);

                $fraisParVehicule = $depQuery->get(['beneficiaire_id', 'montant'])->groupBy('beneficiaire_id');

                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $total = 0.0;
                    foreach ($vehicules as $v) {
                        $total += (float) $fraisParVehicule->get($v->id, collect())->sum('montant');
                    }
                    $fraisParProprio[(string) $proprioId] = $total;
                }
            }

            $depProprioQuery = Depense::where('beneficiaire_type', 'proprietaire')
                ->whereIn('beneficiaire_id', $proprioIds)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('organization_id', $orgId);
            $applyPeriode($depProprioQuery);

            $fraisDirectsParProprio = $depProprioQuery->get(['beneficiaire_id', 'montant'])->groupBy('beneficiaire_id');
            foreach ($fraisDirectsParProprio as $proprioId => $depenses) {
                $fraisParProprio[(string) $proprioId] = ($fraisParProprio[(string) $proprioId] ?? 0.0) + (float) $depenses->sum('montant');
            }
        }

        $premiereEcheanceParProprio = $partsParProprio->map(function ($parts) {
            return $parts
                ->filter(fn (CommissionEnveloppePart $p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->pluck('enveloppe.earned_at')
                ->filter()
                ->sort()
                ->first();
        });

        $periodesParDate = app(PeriodePaiementService::class)->getPeriodsForDates(
            $orgId,
            TypePeriodePaiement::PROPRIETAIRE,
            $premiereEcheanceParProprio->values(),
        );
        $labelsParStatut = ['creee' => 'Créée', 'impaye' => 'Impayé', 'partiel' => 'Partiel', 'paye' => 'Payé', 'annulee' => 'Annulée'];
        $periodesUniques = $periodesParDate->values()->unique('id');
        $teamStatusParPeriode = $periodesUniques->mapWithKeys(
            fn ($periode) => [$periode->id => CommissionAdjustmentService::statutValidationParBeneficiaire($periode)]
        );

        $beneficiaires = $partsParProprio->map(function (Collection $parts, string $proprioId) use (
            $fraisParProprio, $premiereEcheanceParProprio, $periodesParDate, $labelsParStatut, $teamStatusParPeriode,
        ) {
            $premier = $parts->first();

            // total_brut_cumule/total_net_cumule/solde_restant restent calculés exclusivement
            // sur les parts déjà actives (jamais CREEE) — jamais mélangées à une commission pas
            // encore éligible au paiement (cf. compartiments ci-dessous, décision produit du
            // 20/08/2026 « visible ne veut pas dire payable »).
            $partsPayables = $parts->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);
            $totalBrut = (float) $partsPayables->sum('montant_brut');
            $totalAPayer = (float) $partsPayables->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
            $totalFrais = $fraisParProprio[$proprioId] ?? 0.0;
            $totalNet = max(0.0, $totalAPayer - $totalFrais);
            $totalVerse = (float) $partsPayables->sum('montant_verse');
            $solde = max(0.0, $totalNet - $totalVerse);

            $buckets = CommissionKpiBuckets::calculer($parts);

            $statutGlobal = match (true) {
                $partsPayables->isEmpty() && $buckets['en_attente_periode'] > 0.009 => StatutCommission::CREEE->value,
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->value,
                $totalVerse > 0 => StatutCommission::PARTIEL->value,
                default => StatutCommission::IMPAYE->value,
            };

            $premiereEcheance = $premiereEcheanceParProprio->get($proprioId);
            $periode = $premiereEcheance
                ? $periodesParDate->get(PeriodePaiementService::debutKeyForDate(Carbon::parse($premiereEcheance)))
                : null;
            $teamStatus = $periode ? ($teamStatusParPeriode[$periode->id]["proprietaire:{$proprioId}"] ?? null) : null;

            $resolved = CommissionStatusResolver::resolve(
                $periode,
                $teamStatus,
                $statutGlobal,
                $labelsParStatut[$statutGlobal] ?? $statutGlobal,
            );
            $resolved['can_pay'] = false;

            $beneficiaire = $premier->resoudreBeneficiaire();
            $vehicules = $parts->pluck('enveloppe.source.vehicule')->filter()->unique('id')
                ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])->values();
            $agence = $parts->pluck('enveloppe.source.site.nom')->filter()->unique()->sort()->implode(', ');

            return [
                'beneficiaire_id' => $proprioId,
                'beneficiaire_nom' => $beneficiaire?->nom_complet ?? '—',
                'telephone' => $beneficiaire?->telephone,
                'vehicules' => $vehicules->all(),
                'agence' => $agence ?: null,
                'total_brut_cumule' => $totalBrut,
                'total_frais' => $totalFrais,
                'total_net_cumule' => $totalNet,
                'total_verse' => $totalVerse,
                'solde_restant' => $solde,
                'remaining_amount' => $solde,
                'nb_commandes' => $parts->pluck('enveloppe_id')->unique()->count(),
                'statut_global' => $statutGlobal,
                'total_genere' => $buckets['total_genere'],
                'en_attente_periode' => $buckets['en_attente_periode'],
                'payable' => $buckets['payable'],
                ...$resolved,
            ];
        })->values();

        if ($filtreStatut !== '') {
            $beneficiaires = $beneficiaires->filter(fn ($b) => $b['statut_global'] === $filtreStatut);
        }

        if ($filtreNom !== '') {
            $s = mb_strtolower($filtreNom);
            $beneficiaires = $beneficiaires->filter(fn ($b) => str_contains(mb_strtolower((string) $b['beneficiaire_nom']), $s));
        }

        if ($filtreTelephone !== '') {
            $t = preg_replace('/\s/', '', $filtreTelephone);
            $beneficiaires = $beneficiaires->filter(fn ($b) => str_contains(preg_replace('/\s/', '', (string) ($b['telephone'] ?? '')), $t));
        }

        $list = $beneficiaires->values();

        $kpis = [
            'nb_proprietaires' => $list->count(),
            'total_brut' => (float) $list->sum('total_brut_cumule'),
            'total_net' => (float) $list->sum('total_net_cumule'),
            'total_frais' => (float) $list->sum('total_frais'),
            'total_verse' => (float) $list->sum('total_verse'),
            'solde_total' => (float) $list->sum('solde_restant'),
            'total_genere' => (float) $list->sum('total_genere'),
            'en_attente_periode' => (float) $list->sum('en_attente_periode'),
            'payable' => (float) $list->sum('payable'),
        ];

        $earliestDate = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId))
            ->join('commission_enveloppes', 'commission_enveloppes.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->min('commission_enveloppes.earned_at');

        $periodesDisponibles = $earliestDate
            ? self::periodesProprietaireBetween(Carbon::parse($earliestDate), now())
            : [];

        $periodeCourante = PeriodeComptableService::periodeCouranteProprietaire();

        $dateAffichee = $filtrePeriode !== ''
            ? PeriodeComptableService::dateRangeForCode($filtrePeriode)[0]
            : now();
        $periodeAffichee = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::PROPRIETAIRE, $dateAffichee);

        return Inertia::render('Comptabilite/CommissionProprietaire/Index', [
            'beneficiaires' => $list,
            'kpis' => $kpis,
            'filtre_nom' => $filtreNom,
            'filtre_telephone' => $filtreTelephone,
            'filtre_statut' => $filtreStatut,
            'filtre_site_ids' => $filtreSiteIds,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_courante' => $periodeCourante,
            'periode_affichee' => $periodeAffichee ? [
                'id' => $periodeAffichee->id,
                'reference' => $periodeAffichee->reference,
                'statut' => $periodeAffichee->statut->value,
                'statut_label' => $periodeAffichee->statut_label,
            ] : null,
            'sites' => $sites,
            'can_payer' => false,
        ]);
    }

    public function show(Request $request, string $proprietaireId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $proprio = Proprietaire::find($proprietaireId);
        $nom = $proprio ? trim(($proprio->prenom ?? '').' '.($proprio->nom ?? '')) : '—';

        $allParts = CommissionEnveloppePart::with(['enveloppe.source.site', 'enveloppe.source.vehicule'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->where('beneficiaire_id', $proprietaireId)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('enveloppe_id')
            ->get();

        $vehicules = Vehicule::where('proprietaire_id', $proprietaireId)
            ->where('organization_id', $orgId)
            ->get(['id', 'nom_vehicule', 'immatriculation'])
            ->keyBy('id');
        $ownedVehiculeIds = $vehicules->keys();

        $periodeCourante = PeriodeComptableService::periodeCouranteProprietaire();
        $filters = CommissionDetailFilters::fromRequest($request);
        $periodeFilter = $filters['periode'];
        $vehiculeIds = $filters['vehicule_ids'];
        $siteIds = $filters['site_ids'];

        $fraisDepensesQuery = Depense::with(['depenseType:id,libelle', 'user', 'validateur'])
            ->where('organization_id', $orgId)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where(function ($query) use ($ownedVehiculeIds, $proprietaireId, $vehiculeIds) {
                if (empty($vehiculeIds)) {
                    $query->where(function ($q) use ($proprietaireId) {
                        $q->where('beneficiaire_type', 'proprietaire')
                            ->where('beneficiaire_id', $proprietaireId);
                    });
                }

                if ($ownedVehiculeIds->isNotEmpty()) {
                    $matchingVehiculeIds = empty($vehiculeIds)
                        ? $ownedVehiculeIds
                        : $ownedVehiculeIds->intersect($vehiculeIds);

                    if ($matchingVehiculeIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($matchingVehiculeIds) {
                            $q->where('beneficiaire_type', 'vehicule')
                                ->whereIn('beneficiaire_id', $matchingVehiculeIds);
                        });
                    }
                }
            });

        if ($periodeFilter !== '') {
            [$debutDep, $finDep] = PeriodeComptableService::dateRangeForCode($periodeFilter);
            $fraisDepensesQuery->whereBetween('date_depense', [$debutDep->toDateString(), $finDep->toDateString().' 23:59:59']);
        }

        if (! empty($siteIds)) {
            $fraisDepensesQuery->whereIn('site_id', $siteIds);
        }

        $fraisDepensesAffichees = $fraisDepensesQuery->orderByDesc('date_depense')->get();
        $totalFraisDepenses = (float) $fraisDepensesAffichees->sum('montant');

        $earliestCommission = $allParts
            ->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->earned_at !== null)
            ->sortBy(fn (CommissionEnveloppePart $p) => $p->enveloppe->earned_at)
            ->first();
        $earliestDate = $earliestCommission?->enveloppe?->earned_at ?? now();
        $periodesDisponibles = self::periodesProprietaireBetween(Carbon::parse($earliestDate), now());

        $vehiculesDisponibles = $allParts
            ->map(fn (CommissionEnveloppePart $p) => $p->enveloppe?->source?->vehicule)
            ->filter()
            ->unique('id')
            ->sortBy('nom_vehicule')
            ->map(fn ($v) => ['id' => $v->id, 'nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
            ->values();

        $agencesDisponibles = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);

        $filteredParts = $allParts->filter(function (CommissionEnveloppePart $p) use ($periodeFilter, $vehiculeIds, $siteIds) {
            $source = $p->enveloppe?->source;

            if ($periodeFilter !== '') {
                $earnedAt = $p->enveloppe?->earned_at;
                if (! $earnedAt || PeriodeComptableService::codeForProprietaire(Carbon::parse($earnedAt)) !== $periodeFilter) {
                    return false;
                }
            }

            if (! empty($vehiculeIds) && ! in_array($source?->vehicule_id, $vehiculeIds, true)) {
                return false;
            }

            if (! empty($siteIds) && ! in_array($source?->site_id, $siteIds, true)) {
                return false;
            }

            return true;
        });

        // total_brut/total_net/solde restent calculés exclusivement sur les parts déjà actives
        // (jamais CREEE) — jamais mélangées à une commission pas encore éligible au paiement
        // (cf. $buckets ci-dessous, décision produit du 20/08/2026).
        $activeParts = $filteredParts->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);
        $totalBrut = (float) $activeParts->sum('montant_brut');
        $totalAPayer = (float) $activeParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
        $totalNet = max(0.0, $totalAPayer - $totalFraisDepenses);
        $totalVerse = (float) $activeParts->sum('montant_verse');

        $solde = max(0.0, (float) $activeParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer) - $totalFraisDepenses - (float) $activeParts->sum('montant_verse'));
        $buckets = CommissionKpiBuckets::calculer($filteredParts);

        if ($solde > 0.009) {
            $earliestUnpaidDate = $activeParts
                ->filter(fn (CommissionEnveloppePart $p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->map(fn (CommissionEnveloppePart $p) => $p->enveloppe?->earned_at)
                ->filter()
                ->sort()
                ->first();
            $periodeResolue = $earliestUnpaidDate
                ? app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::PROPRIETAIRE, Carbon::parse($earliestUnpaidDate))
                : null;
        } elseif ($activeParts->isEmpty() && $buckets['en_attente_periode'] > 0.009) {
            // Rien de payable ni de payé, seulement des commissions CREEE : aucune période à
            // résoudre, le resolver retombera sur "creee" via sa branche periode === null.
            $periodeResolue = null;
        } else {
            $periodeResolue = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::PROPRIETAIRE, now());
        }

        $teamStatus = $periodeResolue
            ? (CommissionAdjustmentService::statutValidationParBeneficiaire($periodeResolue)["proprietaire:{$proprietaireId}"] ?? null)
            : null;

        $paymentValue = match (true) {
            $solde > 0.009 => StatutCommission::IMPAYE->value,
            $activeParts->isEmpty() && $buckets['en_attente_periode'] > 0.009 => StatutCommission::CREEE->value,
            default => StatutCommission::PAYE->value,
        };
        $paymentLabel = ['impaye' => 'Impayé', 'creee' => 'Créée', 'paye' => 'Payé'][$paymentValue] ?? $paymentValue;
        $statutCommission = CommissionStatusResolver::resolve($periodeResolue, $teamStatus, $paymentValue, $paymentLabel);
        $statutCommission['can_pay'] = false;
        $payable = false;

        $historiqueCommandes = $filteredParts
            ->groupBy('enveloppe_id')
            ->map(function (Collection $partsGroup) {
                $first = $partsGroup->first();
                $enveloppe = $first->enveloppe;
                $source = $enveloppe?->source;
                $periodeCode = $enveloppe?->earned_at
                    ? PeriodeComptableService::codeForProprietaire(Carbon::parse($enveloppe->earned_at))
                    : null;

                $montantAPayer = (float) $partsGroup->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
                $montantVerse = (float) $partsGroup->sum('montant_verse');

                return [
                    'commission_id' => $enveloppe?->id,
                    'reference' => $source?->reference,
                    'date' => $enveloppe?->earned_at ? Carbon::parse($enveloppe->earned_at)->format(self::DATE_FORMAT) : null,
                    'site' => $source?->site?->nom,
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
                ->where('beneficiaire_type', 'proprietaire')
                ->where('beneficiaire_id', $proprietaireId))
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

        return Inertia::render('Comptabilite/CommissionProprietaire/Show', [
            'proprietaire' => [
                'id' => $proprietaireId,
                'nom' => $nom,
                'telephone' => $proprio?->telephone,
            ],
            'commission_summary' => CommissionSummaryFormatter::format(
                $totalBrut,
                $totalFraisDepenses,
                $totalNet,
                $totalVerse,
                $solde,
                $buckets,
            ),
            'expenses' => $fraisDepensesAffichees->map(function ($d) use ($vehicules) {
                $vehicule = $d->beneficiaire_type === 'vehicule'
                    ? $vehicules->get($d->beneficiaire_id)
                    : null;

                return [
                    'id' => $d->id,
                    'date' => $d->date_depense->format(self::DATE_FORMAT),
                    'type' => $d->depenseType?->libelle ?? '—',
                    'commentaire' => $d->commentaire,
                    'saisi_par' => $d->user?->name,
                    'validateur' => $d->validateur?->name,
                    'vehicule' => $vehicule ? [
                        'id' => $vehicule->id,
                        'nom' => $vehicule->nom_vehicule,
                        'immatriculation' => $vehicule->immatriculation,
                    ] : null,
                    'montant' => (float) $d->montant,
                ];
            })->values(),
            'commission_details' => $historiqueCommandes,
            'payments' => $historiquePaiements,
            'modes_paiement' => ModePaiement::options(),
            'periode_courante' => $periodeCourante,
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'payable' => $payable,
            'statut_commission' => $statutCommission,
            'periode_affichee' => $periodeResolue ? [
                'id' => $periodeResolue->id,
                'reference' => $periodeResolue->reference,
                'statut' => $periodeResolue->statut->value,
                'statut_label' => $periodeResolue->statut_label,
            ] : null,
            'filters' => [
                'periode' => $periodeFilter,
                'vehicule_ids' => $vehiculeIds,
                'site_ids' => $siteIds,
                'periode_range' => $periodeRange,
            ],
            'vehicules_disponibles' => $vehiculesDisponibles,
            'agences_disponibles' => $agencesDisponibles,
            'can_payer' => false,
        ]);
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

    // ── Exports ───────────────────────────────────────────────────────────────
    //
    // Source CommissionEnveloppePart. Aucune exclusion CREEE ici — comportement
    // volontaire, la commission propriétaire s'exporte quel que soit son statut.

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreStatut = trim((string) $request->input('statut', ''));
        $filtreNom = trim((string) $request->input('nom', ''));
        $filtreTelephone = trim((string) $request->input('telephone', ''));

        [$parts, $fraisParProprio, $motifsParProprio] = $this->loadPartsForExport($orgId, $filtrePeriode);
        $rows = $this->buildExportRows($parts, $fraisParProprio, $motifsParProprio, $filtrePeriode, $filtreStatut, $filtreNom, $filtreTelephone);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-proprietaires-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Total cumulé (GNF)', 'Dépenses (GNF)', 'Motif de dépense', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
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
        $filtreNom = trim((string) $request->input('nom', ''));
        $filtreTelephone = trim((string) $request->input('telephone', ''));

        [$parts, $fraisParProprio, $motifsParProprio] = $this->loadPartsForExport($orgId, $filtrePeriode);
        $rows = $this->buildExportRows($parts, $fraisParProprio, $motifsParProprio, $filtrePeriode, $filtreStatut, $filtreNom, $filtreTelephone);
        $siteGroups = $this->buildSiteGroups($rows);

        $org = Organization::find($orgId);
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.index', [
            'title' => 'Commissions propriétaire',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'filters' => ['statut' => $filtreStatut, 'nom' => $filtreNom, 'telephone' => $filtreTelephone],
            'sites' => $siteGroups,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-proprietaires-'.now()->format('Y-m-d').'.pdf');
    }

    /** @return array{0: Collection<int, CommissionEnveloppePart>, 1: array<string, float>, 2: array<string, string>} */
    private function loadPartsForExport(string $orgId, string $filtrePeriode): array
    {
        $query = CommissionEnveloppePart::with(['enveloppe.source.site:id,nom', 'enveloppe.source.vehicule:id,nom_vehicule,immatriculation'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PROPRIETAIRE)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });

        $parts = $query->get();

        $proprioIds = $parts->pluck('beneficiaire_id')->filter()->unique();
        $fraisParProprio = [];
        $motifsParProprio = [];

        if ($proprioIds->isNotEmpty()) {
            $vehiculesByProprio = Vehicule::whereIn('proprietaire_id', $proprioIds)
                ->where('organization_id', $orgId)
                ->get(['id', 'proprietaire_id', 'nom_vehicule', 'immatriculation'])
                ->groupBy('proprietaire_id');

            $allVehiculeIds = $vehiculesByProprio->flatten()->pluck('id');

            $applyPeriode = function ($query) use ($filtrePeriode) {
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $query->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
                }

                return $query;
            };

            if ($allVehiculeIds->isNotEmpty()) {
                $depQuery = Depense::with('depenseType:id,libelle')
                    ->where('beneficiaire_type', 'vehicule')
                    ->whereIn('beneficiaire_id', $allVehiculeIds)
                    ->where('statut', StatutDepense::VALIDE->value)
                    ->where('organization_id', $orgId);
                $applyPeriode($depQuery);

                $depenses = $depQuery->get()->groupBy('beneficiaire_id');

                foreach ($vehiculesByProprio as $proprioId => $vehicules) {
                    $proprioDeps = $vehicules->flatMap(fn ($v) => $depenses->get($v->id, collect()));
                    $fraisParProprio[(string) $proprioId] = (float) $proprioDeps->sum('montant');
                    $motifsParProprio[(string) $proprioId] = $proprioDeps
                        ->pluck('depenseType.libelle')->filter()->unique()->implode(', ');
                }
            }

            $depProprioQuery = Depense::with('depenseType:id,libelle')
                ->where('beneficiaire_type', 'proprietaire')
                ->whereIn('beneficiaire_id', $proprioIds)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('organization_id', $orgId);
            $applyPeriode($depProprioQuery);

            foreach ($depProprioQuery->get()->groupBy('beneficiaire_id') as $proprioId => $depenses) {
                $proprioId = (string) $proprioId;
                $fraisParProprio[$proprioId] = ($fraisParProprio[$proprioId] ?? 0.0) + (float) $depenses->sum('montant');
                $motifs = $depenses->pluck('depenseType.libelle')->filter()->unique();
                $motifsParProprio[$proprioId] = trim(
                    ($motifsParProprio[$proprioId] ?? '').(($motifsParProprio[$proprioId] ?? '') !== '' && $motifs->isNotEmpty() ? ', ' : '').$motifs->implode(', '),
                    ', ',
                );
            }
        }

        return [$parts, $fraisParProprio, $motifsParProprio];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function buildExportRows(Collection $parts, array $fraisParProprio, array $motifsParProprio, string $filtrePeriode, string $filtreStatut, string $filtreNom, string $filtreTelephone): Collection
    {
        $rows = $parts->groupBy('beneficiaire_id')->map(function (Collection $propParts, string $proprioId) use ($fraisParProprio, $motifsParProprio, $filtrePeriode) {
            $first = $propParts->first();
            $beneficiaire = $first->resoudreBeneficiaire();
            $totalBrut = (float) $propParts->sum('montant_brut');
            $totalAPayer = (float) $propParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
            $totalFrais = $fraisParProprio[$proprioId] ?? 0.0;
            $totalNet = max(0.0, $totalAPayer - $totalFrais);
            $totalVerse = (float) $propParts->sum('montant_verse');
            $solde = max(0.0, $totalNet - $totalVerse);

            $vehicules = $propParts->pluck('enveloppe.source.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
                ->values();

            $agence = $propParts->pluck('enveloppe.source.site.nom')
                ->filter()->unique()->sort()->implode(', ');

            $motifs = $motifsParProprio[$proprioId] ?? null;

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $propParts->pluck('enveloppe.earned_at')
                    ->filter()
                    ->map(fn ($d) => PeriodeComptableService::labelForCode(
                        PeriodeComptableService::codeForProprietaire(Carbon::parse($d))
                    ))
                    ->unique()->implode(', ');

            $statut = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->label(),
                $totalVerse > 0 => StatutCommission::PARTIEL->label(),
                default => StatutCommission::IMPAYE->label(),
            };

            return [
                'beneficiaire_id' => $proprioId,
                'beneficiaire_nom' => $beneficiaire?->nom_complet ?? '—',
                'telephone' => $beneficiaire?->telephone,
                'vehicules' => $vehicules->all(),
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_cumule' => $totalBrut,
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

        if ($filtreNom !== '') {
            $s = mb_strtolower($filtreNom);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['beneficiaire_nom']), $s));
        }

        if ($filtreTelephone !== '') {
            $t = preg_replace('/\s/', '', $filtreTelephone);
            $rows = $rows->filter(fn ($r) => str_contains(preg_replace('/\s/', '', (string) ($r['telephone'] ?? '')), $t));
        }

        return $rows->sortBy('beneficiaire_nom')->values();
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
