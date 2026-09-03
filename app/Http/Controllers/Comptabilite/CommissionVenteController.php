<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Enums\MotifAjustementCommission;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFichePaiement;
use App\Models\Site;
use App\Models\VehiculeCapacite;
use App\Services\CommissionAdjustmentService;
use App\Services\CommissionStatusResolver;
use App\Services\CommissionVenteCalculatorService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionDetailFilters;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionProcessusFilter;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
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

    /**
     * Écrans "Commission livreur vente" — source CommissionEnveloppePart. Calcul en
     * collection plutôt qu'une agrégation SQL brute : les volumes sont bornés par
     * organisation, la performance n'est pas un enjeu à cette échelle, et une
     * collection reste plus simple à garder correcte.
     *
     * Paiement : jamais depuis cet écran — `can_pay` est toujours false, la seule
     * chaîne de paiement valide passe par Comptabilité > Fiches de paiement.
     */
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
        // Décision produit du 02/09/2026 (révise ce commentaire) : plus de repli implicite sur
        // "vente" — aucune sélection = "Tous les processus" (jamais un mélange silencieusement
        // réduit à un seul), plusieurs processus cochés s'unissent (cf. docs/commissions.md et
        // CommissionProcessusFilter). Chaque ligne du détail affiche sa provenance via une colonne
        // "Processus" dédiée (cf. breakdownParProcessus() côté fiche bénéficiaire pour l'équivalent
        // consolidé par bénéficiaire unique).
        $filtreProcessus = CommissionProcessusFilter::normaliserCodes($request->input('processus', []));

        $isAdmin = $user->isAdmin();
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $query = CommissionEnveloppePart::with([
            'enveloppe.source.site:id,nom',
            'enveloppe.source.vehicule:id,nom_vehicule,immatriculation,type_vehicule_id,proprietaire_id',
            'enveloppe.source.vehicule.typeVehicule:id,nom',
            'enveloppe.source.vehicule.proprietaire:id,personne_id',
            'enveloppe.source.vehicule.proprietaire.personne',
            'enveloppe.source.vehicule.capacites.categorie',
        ])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            // Une commission CREEE existe déjà en base et doit rester visible (décision produit
            // du 20/08/2026 — « visible ne veut pas dire payable ») : seule ANNULEE, qui ne
            // représente plus de créance réelle, est exclue ici. Le détail payable/en attente de
            // période est ventilé plus bas via CommissionKpiBuckets, jamais en cachant la ligne.
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });
        CommissionProcessusFilter::appliquer($query, $filtreProcessus);

        if ($isAdmin && ! empty($filtreSiteIds)) {
            $query->whereHas('enveloppe.source', fn ($q) => $q->whereIn('site_id', $filtreSiteIds));
        } elseif (! $isAdmin && ! empty($siteIds)) {
            $query->whereHas('enveloppe.source', fn ($q) => $q->whereIn('site_id', $siteIds));
        }

        $allParts = $query->get();
        $partsParLivreur = $allParts->groupBy('beneficiaire_id');

        $allLivreurIds = $partsParLivreur->keys()->map(fn ($id) => (string) $id)->all();
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur($orgId, $allLivreurIds, $filtrePeriode);

        $agencesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('enveloppe.source.site.nom')->filter()->unique()->sort()->implode(', '));

        $vehiculesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('enveloppe.source.vehicule')->filter()->unique('id')
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'type' => $v->typeVehicule?->nom,
                'capacites' => $v->capacites->map(fn (VehiculeCapacite $c) => [
                    'categorie_nom' => $c->categorie->nom,
                    'capacite_max' => $c->capacite_max,
                ])->values()->all(),
                'proprietaire_nom' => $v->proprietaire
                    ? trim($v->proprietaire->prenom.' '.$v->proprietaire->nom)
                    : null,
                'proprietaire_telephone' => $v->proprietaire?->telephone,
                'proprietaire_code_phone_pays' => $v->proprietaire?->code_phone_pays,
            ])
            ->values());

        $premiereEcheanceParLivreur = $partsParLivreur->map(function ($parts) {
            return $parts
                ->filter(fn (CommissionEnveloppePart $p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->pluck('enveloppe.earned_at')
                ->filter()
                ->sort()
                ->first();
        });

        $periodesParDate = app(PeriodePaiementService::class)->getPeriodsForDates(
            $orgId,
            TypePeriodePaiement::LIVREUR,
            $premiereEcheanceParLivreur->values(),
        );
        $labelsParStatut = ['impaye' => 'Impayé', 'partiel' => 'Partiel', 'paye' => 'Payé', 'annulee' => 'Annulée'];
        $periodesUniques = $periodesParDate->values()->unique('id');
        $teamStatusParPeriode = $periodesUniques->mapWithKeys(
            fn ($periode) => [$periode->id => CommissionAdjustmentService::statutValidationParBeneficiaire($periode)]
        );

        $beneficiaires = $partsParLivreur->map(function (Collection $parts, string $livreurId) use (
            $agencesParLivreur, $vehiculesParLivreur, $fraisDepensesParLivreur,
            $premiereEcheanceParLivreur, $periodesParDate, $labelsParStatut, $teamStatusParPeriode,
        ) {
            $premier = $parts->first();
            $fraisDepenses = $fraisDepensesParLivreur[$livreurId] ?? 0.0;

            // total_brut_cumule/total_net_cumule/total_verse/solde_restant sont désormais
            // calculés sur TOUTES les parts actives (CREEE incluse) — décision produit du
            // 29/08/2026, qui affine « visible ne veut pas dire payable » (20/08/2026) :
            // le MONTANT affiché doit toujours refléter la valeur retenue courante
            // (montant_actuel ?? montant_net), la VALIDATION ne conditionnant que le
            // droit au paiement (statutGlobal ci-dessous), jamais l'affichage du montant.
            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $parts->sum('montant_brut'),
                0.0, // pas de frais_supplementaires sur CommissionEnveloppePart
                (float) $parts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
                $fraisDepenses,
                (float) $parts->sum('montant_verse'),
            );

            $buckets = CommissionKpiBuckets::calculer($parts);

            // Parts encore CREEE et pas encore pré-validées (validated_at) de ce bénéficiaire :
            // la liste sert de plan de travail — ces id sont ce qu'envoient les actions
            // Ajuster/Valider directement depuis cet écran (CommissionAjustementController::
            // ajusterParts()/validerParts()), sans jamais passer par une PaiementPeriode. Exclure
            // les parts déjà validées fait disparaître le bouton Valider une fois le travail fait
            // sur cette ligne — validerPart() ne touche jamais le statut CREEE lui-même (il ne
            // bascule qu'à la validation de la période, cf. activerCommissionsCreees()), donc
            // sans ce filtre sur validated_at le bouton resterait affiché indéfiniment.
            $creeeParts = $parts
                ->filter(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::CREEE && $p->validated_at === null)
                ->map(fn (CommissionEnveloppePart $p) => ['id' => $p->id, 'montant' => $p->montant_a_payer])
                ->values();

            // Le STATUT (badge) reste gouverné exclusivement par la validation/période — jamais
            // par le fait que le montant soit désormais toujours affiché : un livreur dont TOUTES
            // les commissions sont encore CREEE n'a rien de payable, quel que soit le montant net
            // affiché à côté.
            $aUnePartPayable = $parts->contains(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);
            $statutGlobal = ! $aUnePartPayable && $buckets['en_attente_periode'] > 0.009
                ? StatutCommission::CREEE->value
                : $resume['statut'];

            $premiereEcheance = $premiereEcheanceParLivreur->get($livreurId);
            $periode = $premiereEcheance
                ? $periodesParDate->get(PeriodePaiementService::debutKeyForDate(Carbon::parse($premiereEcheance)))
                : null;
            $teamStatus = $periode ? ($teamStatusParPeriode[$periode->id]["livreur:{$livreurId}"] ?? null) : null;

            $resolved = CommissionStatusResolver::resolve(
                $periode,
                $teamStatus,
                $statutGlobal,
                $labelsParStatut[$statutGlobal] ?? $statutGlobal,
            );
            // Jamais payable depuis cet écran : le paiement passe uniquement
            // par Comptabilité > Fiches de paiement (chaîne de paiement unique).
            $resolved['can_pay'] = false;

            $beneficiaire = $premier->resoudreBeneficiaire();

            return [
                'beneficiaire_id' => $livreurId,
                'beneficiaire_nom' => $beneficiaire?->nom_complet ?? '—',
                'telephone' => $beneficiaire?->telephone,
                'agence' => $agencesParLivreur->get($livreurId),
                'vehicules' => $vehiculesParLivreur->get($livreurId, collect())->values()->all(),
                'total_brut_cumule' => $resume['brut'],
                'total_frais' => $resume['frais'],
                'total_net_cumule' => $resume['net'],
                'total_verse' => $resume['verse'],
                'solde_restant' => $resume['reste'],
                'remaining_amount' => $resume['reste'],
                'nb_commandes' => $parts->pluck('enveloppe_id')->unique()->count(),
                'statut_global' => $statutGlobal,
                'total_genere' => $buckets['total_genere'],
                'en_attente_periode' => $buckets['en_attente_periode'],
                'payable' => $buckets['payable'],
                'creee_parts' => $creeeParts,
                // Toujours exposé, même filtré sur un seul processus (décision produit du
                // 02/09/2026) : la provenance reste visible sans devoir rouvrir le filtre.
                'processus_labels' => CommissionProcessusFilter::labelsPresents($parts),
                ...$resolved,
            ];
        })->values();

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

        $list = $beneficiaires->values();

        $kpis = [
            'nb_livreurs' => $list->count(),
            'total_brut' => (float) $list->sum('total_brut_cumule'),
            'total_net' => (float) $list->sum('total_net_cumule'),
            'total_verse' => (float) $list->sum('total_verse'),
            'solde_total' => (float) $list->sum('solde_restant'),
            'total_genere' => (float) $list->sum('total_genere'),
            'en_attente_periode' => (float) $list->sum('en_attente_periode'),
            'payable' => (float) $list->sum('payable'),
        ];

        $earliestDate = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId))
            ->join('commission_enveloppes', 'commission_enveloppes.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->min('commission_enveloppes.earned_at');

        $periodesDisponibles = $earliestDate
            ? PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate))
            : [];

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();

        $dateAffichee = $filtrePeriode !== ''
            ? PeriodeComptableService::dateRangeForCode($filtrePeriode)[0]
            : now();
        $periodeAffichee = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, $dateAffichee);

        return Inertia::render('Comptabilite/CommissionVente/Index', [
            'beneficiaires' => $list,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_site_ids' => $filtreSiteIds,
            'filtre_processus' => $filtreProcessus,
            'processus_options' => CommissionProcessusFilter::options(),
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
            'motifs' => MotifAjustementCommission::options(),
            // Jamais de paiement direct depuis cet écran, quel que soit le
            // droit "comptabilite.payer" — cf. can_pay forcé à false ci-dessus.
            'can_payer' => false,
        ]);
    }

    public function showLivreur(Request $request, string $livreurId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $livreur = Livreur::find($livreurId);
        $nom = $livreur ? $livreur->libelleAffichage() : '—';

        // Une commission CREEE reste visible ici (décision produit du 20/08/2026) : elle
        // apparaît dans historiqueCommandes avec son propre statut ("Créée"), mais n'entre
        // jamais dans $resume (calculé sur $filteredPartsPourResume, qui l'exclut explicitement
        // plus bas) — jamais mélangée aux montants déjà éligibles au paiement.
        //
        // Contrairement à index() (écran "Commission vente", vente par défaut), une fiche
        // bénéficiaire affiche par défaut la situation TOUS PROCESSUS confondus (décision
        // produit du 31/08/2026, cf. docs/commissions.md) : on regarde la situation financière
        // globale de la personne, pas seulement son activité de vente. Un filtre explicite
        // (sélecteur de la page) permet de revenir à un processus précis.
        $filtreProcessus = $this->scalarInput($request, 'processus');
        $allPartsQuery = CommissionEnveloppePart::with(['enveloppe.source.site', 'enveloppe.source.vehicule', 'enveloppe.processus'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('beneficiaire_id', $livreurId)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('enveloppe_id');
        $allPartsTousProcessus = $allPartsQuery->get();
        $allParts = $filtreProcessus === ''
            ? $allPartsTousProcessus
            : $allPartsTousProcessus->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->processus?->code === $filtreProcessus)->values();

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $filters = CommissionDetailFilters::fromRequest($request, $periodeCourante);
        $periodeFilter = $filters['periode'];
        $vehiculeIds = $filters['vehicule_ids'];
        $siteIds = $filters['site_ids'];

        $earliestCommission = $allParts
            ->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->earned_at !== null)
            ->sortBy(fn (CommissionEnveloppePart $p) => $p->enveloppe->earned_at)
            ->first();
        $earliestDate = $earliestCommission?->enveloppe?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate));

        $vehiculesDisponibles = $allParts
            ->map(fn (CommissionEnveloppePart $p) => $p->enveloppe?->source?->vehicule)
            ->filter()
            ->unique('id')
            ->sortBy('nom_vehicule')
            ->map(fn ($v) => ['id' => $v->id, 'nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
            ->values();

        $agencesDisponibles = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);

        // $filteredPartsTousProcessus sert à la fois de base pour $filteredParts (filtré ensuite
        // par processus si demandé) et pour breakdownParProcessus() ci-dessous : la même
        // prédicate période/véhicule/agence est appliquée une seule fois, jamais dupliquée.
        $filteredPartsTousProcessus = $allPartsTousProcessus
            ->filter(fn (CommissionEnveloppePart $p) => $this->partMatchesFilters($p, $periodeFilter, $vehiculeIds, $siteIds))
            ->values();
        $filteredParts = $filtreProcessus === ''
            ? $filteredPartsTousProcessus
            : $filteredPartsTousProcessus->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->processus?->code === $filtreProcessus)->values();

        // Répartition Vente/Distribution/Transfert affichée uniquement en vue "Tous les
        // processus" — inutile (et redondante) une fois un processus précis sélectionné, où tout
        // ce qui est affiché appartient déjà à ce seul processus.
        $breakdownParProcessus = $filtreProcessus === ''
            ? $this->breakdownParProcessus($filteredPartsTousProcessus)
            : null;

        $fraisDepenses = CommissionVenteCalculatorService::fraisDepenseLivreur(
            $orgId,
            $livreurId,
            $periodeFilter !== '' ? $periodeFilter : null,
            $siteIds,
        );

        // $resume porte désormais sur TOUTES les parts filtrées actives (CREEE incluse) — même
        // règle qu'index() : le montant affiché (net_a_payer/reste_a_payer) reflète toujours la
        // valeur retenue courante, indépendamment de la validation de la période (décision
        // produit du 29/08/2026). $statutResume, lui, reste conditionné par la payabilité.
        $resume = CommissionVenteCalculatorService::calculerResume(
            (float) $filteredParts->sum('montant_brut'),
            0.0,
            (float) $filteredParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
            $fraisDepenses,
            (float) $filteredParts->sum('montant_verse'),
        );

        $buckets = CommissionKpiBuckets::calculer($filteredParts);
        $aUnePartPayable = $filteredParts->contains(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);
        $statutResume = ! $aUnePartPayable && $buckets['en_attente_periode'] > 0.009
            ? StatutCommission::CREEE->value
            : $resume['statut'];

        if ($statutResume !== StatutCommission::PAYE->value && $statutResume !== StatutCommission::CREEE->value) {
            $earliestUnpaidDate = $filteredParts
                ->filter(fn (CommissionEnveloppePart $p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->map(fn (CommissionEnveloppePart $p) => $p->enveloppe?->earned_at)
                ->filter()
                ->sort()
                ->first();
            $periodeResolue = $earliestUnpaidDate
                ? app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, Carbon::parse($earliestUnpaidDate))
                : null;
        } elseif ($statutResume === StatutCommission::CREEE->value) {
            // Rien de payable ni de payé : aucune période à résoudre, le resolver retombera sur
            // "creee" via sa branche periode === null.
            $periodeResolue = null;
        } else {
            $periodeResolue = app(PeriodePaiementService::class)->getPeriodByDate($orgId, TypePeriodePaiement::LIVREUR, now());
        }

        $teamStatus = $periodeResolue
            ? (CommissionAdjustmentService::statutValidationParBeneficiaire($periodeResolue)["livreur:{$livreurId}"] ?? null)
            : null;

        $labelsParStatut = ['creee' => 'Créée', 'impaye' => 'Impayé', 'partiel' => 'Partiel', 'paye' => 'Payé', 'annulee' => 'Annulée'];
        $statutCommission = CommissionStatusResolver::resolve(
            $periodeResolue,
            $teamStatus,
            $statutResume,
            $labelsParStatut[$statutResume] ?? $statutResume,
        );
        // Jamais payable depuis cet écran, cf. docblock de showLivreur().
        $statutCommission['can_pay'] = false;
        $payable = false;

        $periodeStats = null;
        if ($periodeFilter !== '' && $filteredParts->isNotEmpty()) {
            $netPeriode = (float) $filteredParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);
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
            ->groupBy('enveloppe_id')
            ->map(function (Collection $partsGroup) {
                $first = $partsGroup->first();
                $enveloppe = $first->enveloppe;
                $source = $enveloppe?->source;
                $periodeCode = $enveloppe?->earned_at
                    ? PeriodeComptableService::codeForLivreur(Carbon::parse($enveloppe->earned_at))
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
                    'frais' => 0.0,
                    'montant' => $montantAPayer,
                    'paye' => $montantVerse,
                    'reste' => max(0.0, $montantAPayer - $montantVerse),
                    'statut' => $first->statut?->label(),
                    'statut_dot_class' => $first->statut instanceof StatutCommission ? $first->statut->dotClass() : 'bg-zinc-400 dark:bg-zinc-500',
                    'periode' => $periodeCode,
                    'periode_label' => $periodeCode ? PeriodeComptableService::labelForCode($periodeCode) : null,
                    // Origine (Vente/Distribution client/Transfert logistique) : toujours exposée,
                    // même en vue "Tous les processus" — jamais de montants mélangés sans
                    // indication (cf. docs/commissions.md).
                    'processus' => $enveloppe?->processus?->code,
                    'processus_label' => CommissionProcessusFilter::labelFor($enveloppe?->processus?->code),
                ];
            })
            ->values();

        $historiquePaiementsQuery = PaiementFichePaiement::with('createur')
            ->whereHas('fiche', fn ($q) => $q->where('organization_id', $orgId)
                ->where('beneficiaire_type', 'livreur')
                ->where('beneficiaire_id', $livreurId))
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
                $buckets,
            ),
            'commission_details' => $historiqueCommandes,
            'payments' => $historiquePaiements,
            'filtre_processus' => $filtreProcessus,
            'processus_options' => CommissionProcessusFilter::optionsAvecTous(),
            'breakdown_par_processus' => $breakdownParProcessus,
            'expenses' => $expenses,
            'modes_paiement' => ModePaiement::options(),
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $periodeFilter,
            'periodes_disponibles' => $periodesDisponibles,
            'periode_stats' => $periodeStats,
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

    /**
     * Prédicat période/véhicule/agence partagé entre $filteredParts (éventuellement restreint à
     * un processus) et $filteredPartsTousProcessus (base de breakdownParProcessus()) — écrit une
     * seule fois pour éviter toute divergence entre les deux.
     */
    private function partMatchesFilters(CommissionEnveloppePart $part, string $periodeFilter, array $vehiculeIds, array $siteIds): bool
    {
        $source = $part->enveloppe?->source;

        if ($periodeFilter !== '') {
            $earnedAt = $part->enveloppe?->earned_at;
            if (! $earnedAt || PeriodeComptableService::codeForLivreur(Carbon::parse($earnedAt)) !== $periodeFilter) {
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
    }

    /**
     * Répartition Vente / Distribution client / Transfert logistique du total généré, affichée
     * sur la fiche bénéficiaire en vue "Tous les processus" (cf. docs/commissions.md). Les 3
     * codes sont toujours renvoyés (même à 0) pour un affichage stable ; leur somme est
     * exactement égale au total_genere consolidé (CommissionKpiBuckets::calculer() appliqué à la
     * même partition des parts, jamais un recalcul indépendant) — aucune commission perdue.
     *
     * @param  Collection<int, CommissionEnveloppePart>  $parts
     * @return array<int, array{code:string,label:string,total_genere:float}>
     */
    private function breakdownParProcessus(Collection $parts): array
    {
        return collect(CommissionProcessusFilter::options())
            ->map(function (array $option) use ($parts) {
                $partsDuProcessus = $parts->filter(
                    fn (CommissionEnveloppePart $p) => $p->enveloppe?->processus?->code === $option['value']
                );

                return [
                    'code' => $option['value'],
                    'label' => $option['label'],
                    'total_genere' => CommissionKpiBuckets::calculer($partsDuProcessus)['total_genere'],
                ];
            })
            ->all();
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
                        'total_genere' => (float) $siteRows->sum('total_genere'),
                        'total_cumule' => (float) $siteRows->sum('total_cumule'),
                        'total_frais' => (float) $siteRows->sum('frais'),
                        'total_net_valide' => (float) $siteRows->sum('net_valide'),
                        'total_deja_paye' => (float) $siteRows->sum('deja_paye'),
                        'total_reste' => (float) $siteRows->sum('reste'),
                    ],
                ];
            });

        return $grouped->isEmpty()
            ? [['site_nom' => null, 'rows' => [], 'totaux' => ['total_genere' => 0, 'total_cumule' => 0, 'total_frais' => 0, 'total_net_valide' => 0, 'total_deja_paye' => 0, 'total_reste' => 0]]]
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
    // Source CommissionEnveloppePart. Les exports reprennent exactement le
    // périmètre visible dans index() : tous les statuts affichés, y compris CREEE,
    // et les mêmes filtres. Seules les parts ANNULEE restent hors de la liste.

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $filtreStatut = $this->scalarInput($request, 'statut');
        $filtreProcessus = CommissionProcessusFilter::normaliserCodes($request->input('processus', []));
        $search = trim((string) $request->input('search', ''));
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds, $filtreProcessus);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('beneficiaire_id')->filter()->unique()->values()->all(),
            $filtrePeriode,
            ! empty($filtreSiteIds) ? $filtreSiteIds : null,
        );
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search, $fraisDepensesParLivreur);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-vente-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Généré (GNF)', 'Brut validé (GNF)', 'Dépenses (GNF)', 'Net validé (GNF)', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['telephone'] ?? '',
                    self::vehiculesEnTexte($row['vehicules'] ?? []),
                    $row['agence'] ?? '',
                    $periodeLabel,
                    number_format((float) $row['total_genere'], 0, ',', ' '),
                    number_format((float) $row['total_cumule'], 0, ',', ' '),
                    number_format((float) $row['frais'], 0, ',', ' '),
                    number_format((float) $row['net_valide'], 0, ',', ' '),
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
        $filtreProcessus = CommissionProcessusFilter::normaliserCodes($request->input('processus', []));
        $search = trim((string) $request->input('search', ''));
        $filtreSiteIds = $isAdmin
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : $this->siteScope->accessibleSiteIds($user)->all();

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSiteIds, $filtreProcessus);
        $fraisDepensesParLivreur = CommissionVenteCalculatorService::fraisDepensesParLivreur(
            $orgId,
            $parts->pluck('beneficiaire_id')->filter()->unique()->values()->all(),
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
            'show_validation_columns' => true,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-vente-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @param  array<int, string>  $filtreSiteIds
     * @param  array<int, string>  $filtreProcessus
     */
    private function loadPartsForExport(string $orgId, string $filtrePeriode, array $filtreSiteIds = [], array $filtreProcessus = []): Collection
    {
        $query = CommissionEnveloppePart::with([
            'enveloppe.source.site:id,nom',
            'enveloppe.source.vehicule:id,nom_vehicule,immatriculation',
        ])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });
        CommissionProcessusFilter::appliquer($query, $filtreProcessus);

        if (! empty($filtreSiteIds)) {
            $query->whereHas('enveloppe.source', fn ($q) => $q->whereIn('site_id', $filtreSiteIds));
        }

        return $query->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function buildExportRows(Collection $parts, string $filtrePeriode, string $filtreStatut, string $search, array $fraisDepensesParLivreur = []): Collection
    {
        $rows = $parts->groupBy('beneficiaire_id')->map(function (Collection $livParts) use ($filtrePeriode, $fraisDepensesParLivreur) {
            $first = $livParts->first();
            $beneficiaire = $first->resoudreBeneficiaire();
            $fraisDepenses = $fraisDepensesParLivreur[(string) $first->beneficiaire_id] ?? 0.0;
            // Même règle que index()/showLivreur() : le résumé porte sur toutes les parts
            // actives (CREEE incluse), seul le statut reste conditionné par la payabilité.
            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $livParts->sum('montant_brut'),
                0.0, // pas de frais_supplementaires sur CommissionEnveloppePart
                (float) $livParts->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
                $fraisDepenses,
                (float) $livParts->sum('montant_verse'),
            );
            $buckets = CommissionKpiBuckets::calculer($livParts);
            $aUnePartPayable = $livParts->contains(fn (CommissionEnveloppePart $part) => $part->statut !== StatutCommission::CREEE);
            $statut = ! $aUnePartPayable && $buckets['en_attente_periode'] > 0.009
                ? StatutCommission::CREEE->value
                : $resume['statut'];

            $vehicules = $livParts->pluck('enveloppe.source.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => ['nom' => $v->nom_vehicule, 'immatriculation' => $v->immatriculation])
                ->values();

            $agence = $livParts->pluck('enveloppe.source.site.nom')
                ->filter()->unique()->sort()->implode(', ');

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $livParts->pluck('enveloppe.earned_at')
                    ->filter()
                    ->map(fn ($d) => PeriodeComptableService::labelForCode(
                        PeriodeComptableService::codeForLivreur(Carbon::parse($d))
                    ))
                    ->unique()->implode(', ');

            return [
                'beneficiaire_id' => $first->beneficiaire_id,
                'beneficiaire_nom' => $beneficiaire?->nom_complet ?? '—',
                'telephone' => $beneficiaire?->telephone,
                'vehicules' => $vehicules->all(),
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_genere' => $buckets['total_genere'],
                'total_cumule' => $resume['brut'],
                'frais' => $resume['frais'],
                'net_valide' => $resume['net'],
                'deja_paye' => $resume['verse'],
                'reste' => $resume['reste'],
                'statut_code' => $statut,
                'statut' => $statut === StatutCommission::CREEE->value
                    ? 'À valider'
                    : StatutCommission::from($statut)->label(),
            ];
        });

        if ($filtreStatut !== '') {
            if (in_array($filtreStatut, array_column(StatutCommission::cases(), 'value'), true)) {
                $rows = $rows->filter(fn ($r) => $r['statut_code'] === $filtreStatut);
            }
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $searchDigits = preg_replace('/\D/', '', $search);
            $rows = $rows->filter(
                fn ($r) => str_contains(mb_strtolower($r['beneficiaire_nom']), $s)
                    || ($searchDigits !== '' && str_contains(
                        preg_replace('/\D/', '', (string) ($r['telephone'] ?? '')),
                        $searchDigits,
                    ))
            );
        }

        return $rows->sortBy('beneficiaire_nom')->values();
    }
}
