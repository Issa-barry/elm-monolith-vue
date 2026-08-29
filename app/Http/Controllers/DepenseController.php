<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Http\Requests\StoreDepenseRequest;
use App\Http\Requests\UpdateDepenseRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Depense;
use App\Models\DepenseImputation;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\DepenseValideeNotification;
use App\Services\AuditLogService;
use App\Services\DepenseImputationService;
use App\Services\DroitCreationDepenseService;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepenseController extends Controller
{
    public function __construct(
        private DepenseImputationService $imputationService,
        private AuditLogService $audit,
        private DroitCreationDepenseService $droitCreationDepense,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $paginator = $this->buildQuery($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->paginate(30)
            ->withQueryString();

        [$beneficiaireCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($paginator->items());

        $droitValidation = $this->droitCreationDepense->droitValidationPour($user, $orgId);

        $types = DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get(['id', 'libelle', 'categorie']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        $statsRow = $this->buildQuery($filters, $orgId, $siteIds)
            ->reorder()
            ->selectRaw(
                'COUNT(*) as total,
                COALESCE(SUM(montant), 0) as montant_total,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as validees',
                [StatutDepense::SOUMIS->value, StatutDepense::VALIDE->value]
            )
            ->first();

        return Inertia::render('Depenses/Index', [
            'depenses' => $paginator->through(fn (Depense $d) => $this->transformDepense($d, $beneficiaireCache, $vehiculeInfoCache, $user, $droitValidation)),
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->libelle, 'categorie' => $t->categorie->value]),
            'sites' => $sites,
            'categories' => CategorieDepense::options(),
            'statuts' => StatutDepense::options(),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
            'stats' => [
                'total' => (int) $statsRow->total,
                'montant_total' => (float) $statsRow->montant_total,
                'en_attente' => (int) $statsRow->en_attente,
                'validees' => (int) $statsRow->validees,
            ],
            'can_create' => $user->can('depenses.create'),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $depenses = $this->buildQuery($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($depenses->all());

        $filename = 'depenses-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($depenses, $labelCache, $vehiculeInfoCache) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Référence', 'Date', 'Type', 'Catégorie', 'Concerné', 'Téléphone concerné',
                'Véhicule', 'Montant (GNF)', 'Dépenses (GNF)', 'Statut', 'Site',
                'Saisi par', 'Validé par', 'Commentaire',
            ], ';');

            foreach ($depenses as $d) {
                $row = $this->transformDepense($d, $labelCache, $vehiculeInfoCache);
                fputcsv($handle, [
                    $d->id,
                    $row['date_depense'],
                    $row['type']['libelle'] ?? '',
                    $row['type']['categorie_label'] ?? '',
                    $row['beneficiaire_label'] ?? '',
                    $row['beneficiaire_telephone'] ?? '',
                    $row['vehicule_nom'] ?? '',
                    number_format((float) $row['montant'], 0, ',', ' '),
                    '0',
                    $d->statut->label(),
                    $row['site']['nom'] ?? '',
                    $row['user']['name'] ?? '',
                    $row['validateur']['name'] ?? '',
                    $row['commentaire'] ?? '',
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $field = $request->input('field', '');
        $q = trim($request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $orgId = auth()->user()->organization_id;
        $like = '%'.$q.'%';

        if ($field === 'vehicule') {
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $q).'%';

            $vehicules = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($qb) => $qb
                    ->where('nom_vehicule', 'LIKE', $like)
                    ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                )
                ->with('vehiculeBeneficiaire:id,nom_vehicule,immatriculation')
                ->get()
                ->pluck('vehiculeBeneficiaire')
                ->filter()
                ->unique('id')
                ->take(8)
                ->map(fn ($v) => [
                    'label' => $v->nom_vehicule.' — '.$v->immatriculation,
                    'value' => $v->immatriculation,
                ])
                ->values();

            return response()->json($vehicules);
        }

        if ($field === 'concerne') {
            $results = collect();

            // livreur/proprietaire : recherche par leur propre nom OU par le nom du véhicule
            // auquel ils sont rattachés (livreur → equipes.vehicule, proprietaire → vehicules) —
            // en pratique, on identifie souvent ces bénéficiaires par le véhicule plutôt que
            // par leur nom propre.
            $vehiculeRelationParType = [
                'livreur' => 'equipes.vehicule',
                'proprietaire' => 'vehicules',
            ];

            foreach (['employe' => 'employeBeneficiaire', 'livreur' => 'livreurBeneficiaire', 'proprietaire' => 'proprietaireBeneficiaire'] as $type => $relation) {
                $vehiculeRelation = $vehiculeRelationParType[$type] ?? null;

                $items = Depense::where('organization_id', $orgId)
                    ->where('beneficiaire_type', $type)
                    ->whereHas($relation, function ($qb) use ($like, $vehiculeRelation) {
                        $qb->where(fn ($q) => $q->whereHas('personne', fn ($p) => $p
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                        ));

                        if ($vehiculeRelation) {
                            $qb->orWhereHas($vehiculeRelation, fn ($v) => $v->where('nom_vehicule', 'LIKE', $like));
                        }
                    })
                    ->with([$relation, $relation.'.personne'])
                    ->get()
                    ->pluck($relation)
                    ->filter()
                    ->unique('id')
                    ->take(4)
                    ->map(fn ($p) => [
                        'label' => trim($p->prenom.' '.$p->nom),
                        'value' => trim($p->prenom.' '.$p->nom),
                    ]);

                $results = $results->merge($items);
            }

            $vehicules = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($qb) => $qb->where('nom_vehicule', 'LIKE', $like))
                ->with('vehiculeBeneficiaire:id,nom_vehicule')
                ->get()
                ->pluck('vehiculeBeneficiaire')
                ->filter()
                ->unique('id')
                ->take(4)
                ->map(fn ($v) => [
                    'label' => $v->nom_vehicule,
                    'value' => $v->nom_vehicule,
                ]);

            $results = $results->merge($vehicules);

            // Prestataire : identité physique (Personne) OU morale (EntrepriseTierce) selon le
            // prestataire — nom_complet est un accesseur PHP, pas une colonne, donc filtré en
            // mémoire plutôt qu'en LIKE SQL (volumes bornés par organisation, cf. convention déjà
            // appliquée aux écrans Commission v2).
            $prestataires = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'prestataire')
                ->with('prestataireBeneficiaire')
                ->get()
                ->pluck('prestataireBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn ($p) => str_contains(mb_strtolower((string) $p->nom_complet), mb_strtolower($q)))
                ->take(4)
                ->map(fn ($p) => [
                    'label' => $p->nom_complet,
                    'value' => $p->nom_complet,
                ]);

            $results = $results->merge($prestataires);

            $clients = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'client')
                ->with('clientBeneficiaire:id,nom,prenom,nom_complet')
                ->get()
                ->pluck('clientBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn (Client $client) => str_contains(mb_strtolower($client->nom_complet), mb_strtolower($q)))
                ->take(4)
                ->map(fn (Client $client) => ['label' => $client->nom_complet, 'value' => $client->nom_complet]);

            $results = $results->merge($clients);

            return response()->json($results->unique('value')->take(8)->values());
        }

        if ($field === 'telephone_concerne') {
            $digits = preg_replace('/\D/', '', $q);
            $likeTel = '%'.($digits ?: $q).'%';
            $results = collect();

            foreach (['employe' => 'employeBeneficiaire', 'livreur' => 'livreurBeneficiaire', 'proprietaire' => 'proprietaireBeneficiaire'] as $type => $relation) {
                $items = Depense::where('organization_id', $orgId)
                    ->where('beneficiaire_type', $type)
                    ->whereHas($relation, fn ($qb) => $qb->whereHas('personne', fn ($p) => $p
                        ->whereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel])
                    ))
                    ->with([$relation, $relation.'.personne'])
                    ->get()
                    ->pluck($relation)
                    ->filter()
                    ->unique('id')
                    ->take(4)
                    ->map(fn ($p) => [
                        'label' => trim($p->prenom.' '.$p->nom).' — '.$p->telephone,
                        'value' => $p->telephone,
                    ]);

                $results = $results->merge($items);
            }

            $prestataires = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'prestataire')
                ->with('prestataireBeneficiaire')
                ->get()
                ->pluck('prestataireBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn ($p) => $p->phone && str_contains(preg_replace('/\D/', '', (string) $p->phone), $digits ?: $q))
                ->take(4)
                ->map(fn ($p) => [
                    'label' => $p->nom_complet.' — '.$p->phone,
                    'value' => $p->phone,
                ]);

            $results = $results->merge($prestataires);

            $clients = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'client')
                ->with('clientBeneficiaire:id,nom,prenom,nom_complet,telephone')
                ->get()
                ->pluck('clientBeneficiaire')
                ->filter(fn (?Client $client) => $client?->telephone)
                ->unique('id')
                ->filter(fn (Client $client) => str_contains(preg_replace('/\D/', '', $client->telephone), $digits ?: $q))
                ->take(4)
                ->map(fn (Client $client) => ['label' => $client->nom_complet.' — '.$client->telephone, 'value' => $client->telephone]);

            $results = $results->merge($clients);

            return response()->json($results->unique('value')->take(8)->values());
        }

        return response()->json([]);
    }

    public function concereneDetail(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $type = $request->query('type');
        $id = $request->query('id');
        $orgId = auth()->user()->organization_id;

        $detail = match ($type) {
            'proprietaire' => $this->buildProprietaireDetail($id, $orgId),
            'livreur' => $this->buildLivreurDetail($id, $orgId),
            'employe' => $this->buildEmployeDetail($id, $orgId),
            'prestataire' => $this->buildPrestataireDetail($id, $orgId),
            'client' => $this->buildClientDetail($id, $orgId),
            default => null,
        };

        if (! $detail) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($detail);
    }

    public function vehiculeDetail(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $id = $request->query('id');
        $orgId = auth()->user()->organization_id;

        $vehicule = Vehicule::where('organization_id', $orgId)
            ->with(['typeVehicule:id,nom', 'proprietaire:id,personne_id', 'proprietaire.personne', 'site:id,nom'])
            ->find($id, ['id', 'nom_vehicule', 'immatriculation', 'type_vehicule_id', 'proprietaire_id', 'site_id', 'categorie']);

        if (! $vehicule) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'nom' => $vehicule->nom_vehicule,
            'immatriculation' => $vehicule->immatriculation,
            'type' => $vehicule->typeVehicule?->nom ?? '—',
            'proprietaire' => $vehicule->proprietaire
                ? trim("{$vehicule->proprietaire->prenom} {$vehicule->proprietaire->nom}")
                : '—',
            'site' => $vehicule->site?->nom ?? '—',
            // Propriété réelle du véhicule — plus jamais reconstruite depuis
            // livraison_logistique (confusion usage/propriété corrigée, cf. Vehicule::categorie).
            'categorie' => $vehicule->categorie->value,
        ]);
    }

    private function buildProprietaireDetail(string $id, string $orgId): ?array
    {
        $p = Proprietaire::with('personne')->where('organization_id', $orgId)->find($id);
        if (! $p) {
            return null;
        }

        return [
            'type' => 'proprietaire',
            'nom' => trim("{$p->prenom} {$p->nom}"),
            'telephone' => $p->telephone ?? '—',
            'adresse' => $p->adresse ?? '—',
            'site' => '—',
        ];
    }

    private function buildLivreurDetail(string $id, string $orgId): ?array
    {
        // EquipeLivraison::nom est un accesseur PHP calculé (vehicule?->nom_vehicule), jamais
        // une colonne réelle de equipes_livraison — le sélectionner via with('equipes:id,nom')
        // provoquait une erreur SQL ("Unknown column"). On charge donc son vehicule (dont
        // l'accesseur dépend) plutôt que de tenter de sélectionner l'accesseur lui-même.
        $l = Livreur::with(['personne', 'equipes.vehicule:id,nom_vehicule'])
            ->where('organization_id', $orgId)
            ->find($id);

        if (! $l) {
            return null;
        }

        return [
            'type' => 'livreur',
            'nom' => $l->libelleAffichage(),
            'telephone' => $l->telephone ?? '—',
            'equipe' => $l->equipes->pluck('nom')->implode(', ') ?: '—',
            'site' => '—',
        ];
    }

    private function buildEmployeDetail(string $id, string $orgId): ?array
    {
        $e = Employe::with(['personne', 'site:id,nom', 'contratActif'])
            ->where('organization_id', $orgId)
            ->find($id);

        if (! $e) {
            return null;
        }

        return [
            'type' => 'employe',
            'nom' => trim("{$e->prenom} {$e->nom}"),
            'telephone' => $e->telephone ?? '—',
            'poste' => $e->type_employe?->label() ?? '—',
            'site' => $e->site?->nom ?? '—',
        ];
    }

    private function buildPrestataireDetail(string $id, string $orgId): ?array
    {
        $p = Prestataire::with(['personne', 'entrepriseTierce'])->where('organization_id', $orgId)->find($id);
        if (! $p) {
            return null;
        }

        return [
            'type' => 'prestataire',
            'nom' => $p->nom_complet ?? '—',
            'telephone' => $p->phone ?? '—',
            'poste' => $p->type_label,
            'site' => '—',
        ];
    }

    private function buildClientDetail(string $id, string $orgId): ?array
    {
        $client = Client::where('organization_id', $orgId)->find($id);
        if (! $client) {
            return null;
        }

        return [
            'type' => 'client',
            'nom' => $client->nom_complet,
            'telephone' => $client->telephone ?? '—',
            'site' => '—',
        ];
    }

    public function imprimer(Request $request): \Illuminate\Http\Response
    {
        $this->authorize('viewAny', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $org = Organization::find($orgId);
        $printedBy = $user->name;
        $now = now();

        $depenses = $this->buildQuery($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($depenses->all());
        $rows = $depenses->map(fn (Depense $d) => $this->transformDepense($d, $labelCache, $vehiculeInfoCache));

        $grouped = $rows->groupBy(fn ($row) => $row['site']['id'] ?? 'sans-site');

        $sites = $grouped->map(fn ($siteRows) => [
            'site_nom' => $siteRows->first()['site']['nom'] ?? null,
            'rows' => $siteRows,
            'total' => $siteRows->sum('montant'),
        ])->values();

        return response()->view('print.depenses', [
            'sites' => $sites,
            'filters' => $filters,
            'org' => $org,
            'printed_by' => $printedBy,
            'generated_at' => $now,
        ]);
    }

    public function show(Depense $depense): Response
    {
        $this->authorize('view', $depense);

        $depense->load(['depenseType', 'site', 'user', 'validateur', 'imputations']);

        $categorie = $depense->depenseType?->categorie;
        $user = auth()->user();

        $vehiculeInfo = ($depense->beneficiaire_type === 'vehicule' && $depense->beneficiaire_id)
            ? $this->resolveVehiculeInfo($depense->beneficiaire_id)
            : null;

        $concerneReelLabel = $vehiculeInfo
            ? $vehiculeInfo['concerne_reel_label']
            : $this->resoudreBeneficiaireLabel($depense->beneficiaire_type, $depense->beneficiaire_id);

        $impactMessage = $vehiculeInfo
            ? $vehiculeInfo['impact_message']
            : ($categorie?->impactMessage() ?? '');

        return Inertia::render('Depenses/Show', [
            'depense' => [
                'id' => $depense->id,
                'date_depense' => $depense->date_depense->toDateString(),
                'montant' => (float) $depense->montant,
                'montant_formatte' => number_format((float) $depense->montant, 0, ',', "\u{202F}"),
                'statut' => $depense->statut->value,
                'statut_label' => $depense->statut->label(),
                'commentaire' => $depense->commentaire,
                'motif_rejet' => $depense->motif_rejet,
                'commentaire_rejet' => $depense->commentaire_rejet,
                'justificatif_path' => $depense->justificatif_path,
                'date_validation' => $depense->date_validation?->toDateTimeString(),
                'created_at' => $depense->created_at->toDateTimeString(),
                'type_libelle' => $depense->depenseType?->libelle ?? '—',
                'categorie' => $categorie?->value ?? '',
                'categorie_label' => $categorie?->label() ?? '',
                'impact_message' => $impactMessage,
                'vehicule_nom' => $vehiculeInfo['vehicule_nom'] ?? null,
                'vehicule_immatriculation' => $vehiculeInfo['vehicule_immatriculation'] ?? null,
                'beneficiaire_label' => $concerneReelLabel,
                'site_nom' => $depense->site?->nom,
                'saisi_par' => $depense->user->name,
                'validateur' => $depense->validateur?->name,
                'imputations' => $depense->imputations->map(fn (DepenseImputation $i) => [
                    'id' => $i->id,
                    'imputation_type' => $i->imputation_type,
                    'beneficiaire_type' => $i->beneficiaire_type,
                    'beneficiaire_label' => $this->resoudreBeneficiaireLabel($i->beneficiaire_type, $i->beneficiaire_id),
                    'montant' => (float) $i->montant,
                    'periode_type' => $i->periode_type,
                    'periode_debut' => $i->periode_debut?->toDateString(),
                    'periode_fin' => $i->periode_fin?->toDateString(),
                    'statut' => $i->statut,
                ])->values(),
                'can_edit' => $user->can('update', $depense),
                'can_submit' => $user->can('view', $depense) && $depense->statut->value === 'brouillon',
                'can_validate' => $user->can('valider', $depense) && $depense->statut->value === 'soumis',
                'can_reject' => $user->can('valider', $depense) && $depense->statut->value === 'soumis',
                'can_delete' => $user->can('delete', $depense),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $initialBeneficiaireType = $request->query('beneficiaire_type') === 'client' ? 'client' : '';
        $initialBeneficiaireId = '';
        if ($initialBeneficiaireType === 'client') {
            $initialBeneficiaireId = (string) Client::where('organization_id', $orgId)
                ->whereKey($request->query('beneficiaire_id'))
                ->value('id');
        }

        $sites = $user->isAdmin()
            ? $this->loadSites($orgId)
            : $user->sites()
                ->where('sites.organization_id', $orgId)
                ->orderBy('sites.nom')
                ->get(['sites.id', 'sites.nom'])
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom]);

        return Inertia::render('Depenses/Create', [
            'types' => $this->loadTypes($orgId),
            'vehicules' => $this->loadVehicules($orgId),
            'sites' => $sites,
            'employes' => $this->loadEmployes($orgId),
            'livreurs' => $this->loadLivreurs($orgId),
            'proprietaires' => $this->loadProprietaires($orgId),
            'prestataires' => $this->loadPrestataires($orgId),
            'clients' => $this->loadClients($orgId),
            'default_site_id' => $this->defaultSiteId(),
            'categories' => CategorieDepense::optionsConcerne(),
            'can_change_site' => $user->isAdmin(),
            'initial_beneficiaire_type' => $initialBeneficiaireType,
            'initial_beneficiaire_id' => $initialBeneficiaireId,
        ]);
    }

    public function store(StoreDepenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        abort_unless(
            $this->droitCreationDepense->peutCreerSurSite($user, $orgId, (string) $request->site_id),
            403,
            'Vous n\'êtes pas autorisé à créer une dépense sur ce site.'
        );

        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($request->site_id, $allowedSiteIds, true),
                403,
                'Vous ne pouvez pas choisir ce site.'
            );
        }

        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        $depense = Depense::create([
            'organization_id' => $orgId,
            'user_id' => auth()->id(),
            'depense_type_id' => $request->depense_type_id,
            'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
            'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
            'site_id' => $request->site_id,
            'montant' => $request->montant,
            'date_depense' => $request->date_depense,
            'commentaire' => $request->commentaire,
            'statut' => $request->statut,
        ]);

        $this->audit->record($depense, AuditEvent::CREATED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => "Dépense créée — {$type->libelle}",
        ]);

        return redirect()->route('depenses.index')->with('success', 'Dépense enregistrée.');
    }

    public function edit(Depense $depense): Response
    {
        $this->authorize('update', $depense);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $sites = $user->isAdmin()
            ? $this->loadSites($orgId)
            : $user->sites()
                ->where('sites.organization_id', $orgId)
                ->orderBy('sites.nom')
                ->get(['sites.id', 'sites.nom'])
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom]);

        return Inertia::render('Depenses/Edit', [
            'depense' => [
                'id' => $depense->id,
                'depense_type_id' => $depense->depense_type_id,
                'beneficiaire_type' => $depense->beneficiaire_type,
                'beneficiaire_id' => $depense->beneficiaire_id,
                'site_id' => $depense->site_id,
                'montant' => (float) $depense->montant,
                'date_depense' => $depense->date_depense->toDateString(),
                'commentaire' => $depense->commentaire ?? '',
                'statut' => $depense->statut->value,
            ],
            'types' => $this->loadTypes($orgId),
            'vehicules' => $this->loadVehicules($orgId),
            'sites' => $sites,
            'employes' => $this->loadEmployes($orgId),
            'livreurs' => $this->loadLivreurs($orgId),
            'proprietaires' => $this->loadProprietaires($orgId),
            'prestataires' => $this->loadPrestataires($orgId),
            'clients' => $this->loadClients($orgId),
            'categories' => CategorieDepense::optionsConcerne(),
            'can_change_site' => $user->isAdmin(),
        ]);
    }

    public function update(UpdateDepenseRequest $request, Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        $user = auth()->user();
        $orgId = $user->organization_id;

        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($request->site_id, $allowedSiteIds, true),
                403,
                'Vous ne pouvez pas choisir ce site.'
            );
        }

        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        $submittableStatuts = [StatutDepense::BROUILLON, StatutDepense::REJETE, StatutDepense::ANNULE];
        $shouldSubmit = $request->input('statut') === 'soumis'
            && in_array($depense->statut, $submittableStatuts, true);

        $fields = ['depense_type_id', 'beneficiaire_type', 'beneficiaire_id', 'site_id', 'montant', 'date_depense', 'commentaire'];
        $before = $depense->only($fields);

        $depense->update([
            'depense_type_id' => $request->depense_type_id,
            'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
            'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
            'site_id' => $request->site_id,
            'montant' => $request->montant,
            'date_depense' => $request->date_depense,
            'commentaire' => $request->commentaire,
        ]);

        [$oldDiff, $newDiff] = $this->audit->diffFields($before, $depense->fresh()->only($fields), $fields);
        $this->audit->record($depense, AuditEvent::UPDATED, $user, $oldDiff, $newDiff, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense modifiée',
        ]);

        if ($shouldSubmit) {
            $depense->update(['statut' => StatutDepense::SOUMIS]);
            $this->audit->record($depense, AuditEvent::SUBMITTED, $user, null, null, [
                'module' => 'depenses',
                'site_id' => $depense->site_id,
                'description' => 'Dépense soumise pour validation',
            ]);

            return redirect()->route('depenses.show', $depense)->with('success', 'Dépense soumise pour validation.');
        }

        return redirect()->route('depenses.show', $depense)->with('success', 'Dépense mise à jour.');
    }

    public function soumettre(Depense $depense): RedirectResponse
    {
        $this->authorize('view', $depense);

        $submittable = [StatutDepense::BROUILLON, StatutDepense::REJETE, StatutDepense::ANNULE];
        if (! in_array($depense->statut, $submittable, true)) {
            return back()->withErrors(['statut' => 'Cette dépense ne peut pas être soumise.']);
        }

        $depense->update(['statut' => StatutDepense::SOUMIS]);
        $this->audit->record($depense, AuditEvent::SUBMITTED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense soumise pour validation',
        ]);

        return back()->with('success', 'Dépense soumise pour validation.');
    }

    public function valider(Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        $user = auth()->user();

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être validées.']);
        }

        $depense->load('depenseType');

        $imputation = null;

        try {
            DB::transaction(function () use ($depense, &$imputation) {
                $depense->update([
                    'statut' => StatutDepense::VALIDE,
                    'validateur_id' => auth()->id(),
                    'date_validation' => now(),
                    'motif_rejet' => null,
                ]);

                $imputation = $this->imputationService->creer($depense);
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['imputation' => $e->getMessage()]);
        }

        $this->notifierDepenseValidee($depense, $imputation);

        $montantFmt = number_format((float) $depense->montant, 0, ',', "\u{202F}");
        $this->audit->record($depense, AuditEvent::VALIDATED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => "Dépense \"{$depense->depenseType?->libelle}\" validée — {$montantFmt} GNF",
        ]);

        return back()->with('success', 'Dépense validée et imputée.');
    }

    /**
     * Scope phase 1 (cf. rapport notifications, 2026-08-27) : seul
     * beneficiaire_type === 'proprietaire' (dépense catégorie VEHICULE imputée
     * au propriétaire du véhicule) déclenche cette notification —
     * livreur/site/salarie/prestataire restent hors périmètre pour l'instant,
     * sans erreur. Jamais de rethrow : un échec d'envoi ne doit jamais faire
     * annuler une validation déjà enregistrée.
     */
    private function notifierDepenseValidee(Depense $depense, ?DepenseImputation $imputation): void
    {
        if ($imputation?->beneficiaire_type !== 'proprietaire') {
            return;
        }

        try {
            $user = BeneficiaireUserResolver::resolve('proprietaire', $imputation->beneficiaire_id);

            $vehiculeNom = $depense->beneficiaire_type === 'vehicule'
                ? ($depense->vehiculeBeneficiaire?->nom_vehicule ?? '—')
                : '—';

            $notif = new DepenseValideeNotification($depense->id, $vehiculeNom, (float) $depense->montant);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'depenses',
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'expense.validated', 'depense_id' => $depense->id],
                ] : null,
            );
        } catch (\Throwable $e) {
            Log::error('DepenseValideeNotification : envoi échoué', [
                'depense_id' => $depense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function rejeter(Request $request, Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être rejetées.']);
        }

        $validated = $request->validate([
            'motif_rejet' => ['required', 'string', 'in:Non conforme,Autre'],
            'commentaire_rejet' => ['required_if:motif_rejet,Autre', 'nullable', 'string', 'min:5', 'max:255'],
        ], [
            'motif_rejet.required' => 'Le motif de rejet est obligatoire.',
            'motif_rejet.in' => 'Le motif sélectionné est invalide.',
            'commentaire_rejet.required_if' => 'Le commentaire est obligatoire pour le motif "Autre".',
            'commentaire_rejet.min' => 'Le commentaire doit faire au moins 5 caractères.',
        ]);

        $depense->update([
            'statut' => StatutDepense::REJETE,
            'validateur_id' => auth()->id(),
            'date_validation' => now(),
            'motif_rejet' => $validated['motif_rejet'],
            'commentaire_rejet' => $validated['motif_rejet'] === 'Autre' ? ($validated['commentaire_rejet'] ?? null) : null,
        ]);

        $this->audit->record($depense, AuditEvent::REJECTED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'motif_rejet' => $validated['motif_rejet'],
            'description' => "Dépense rejetée — motif : {$validated['motif_rejet']}",
        ]);

        return back()->with('success', 'Dépense rejetée.');
    }

    public function destroy(Depense $depense): RedirectResponse
    {
        $this->authorize('delete', $depense);

        $this->audit->record($depense, AuditEvent::DELETED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense supprimée',
        ]);
        $depense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }

    public function historique(Depense $depense): JsonResponse
    {
        abort_unless(auth()->user()->organization_id === $depense->organization_id, 403);
        abort_unless(auth()->user()->can('depenses.read'), 403);

        $logs = AuditLog::where('auditable_type', $depense->getMorphClass())
            ->where('auditable_id', $depense->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'date' => $log->created_at->format('d/m/Y H:i'),
                'acteur' => $log->actor_name_snapshot ?? '—',
                'event_code' => $log->event_code,
                'action' => $log->event_label,
                'description' => $this->buildAuditDescription($log),
            ]);

        return response()->json(['logs' => $logs]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildQuery(array $filters, string $orgId, array $siteIds = []): Builder
    {
        $query = Depense::forOrg($orgId)
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at');

        if (! empty($filters['type'])) {
            $query->whereIn('depense_type_id', (array) $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->whereIn('statut', (array) $filters['statut']);
        }
        if (! empty($filters['categorie'])) {
            $query->whereHas('depenseType', fn ($q) => $q->whereIn('categorie', (array) $filters['categorie']));
        }
        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }
        if (! empty($filters['date_debut'])) {
            $query->where('date_depense', '>=', $filters['date_debut']);
        }
        if (! empty($filters['date_fin'])) {
            $query->where('date_depense', '<=', $filters['date_fin']);
        }

        if (! empty($filters['vehicule'])) {
            $likeVeh = '%'.$filters['vehicule'].'%';
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $filters['vehicule']).'%';
            $query->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($q) => $q
                    ->where('nom_vehicule', 'LIKE', $likeVeh)
                    ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                );
        }

        if (! empty($filters['search'])) {
            $like = '%'.$filters['search'].'%';
            $digits = preg_replace('/\D/', '', $filters['search']);
            $likeTel = $digits ? '%'.$digits.'%' : null;
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $filters['search']).'%';

            $query->where(function ($w) use ($like, $likeTel, $likeImmat) {
                $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";

                $w->where('commentaire', 'LIKE', $like)
                    ->orWhereHas('depenseType', fn ($q) => $q->where('libelle', 'LIKE', $like))
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'vehicule')
                        ->whereHas('vehiculeBeneficiaire', fn ($q) => $q
                            ->where('nom_vehicule', 'LIKE', $like)
                            ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                        )
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'employe')
                        ->whereHas('employeBeneficiaire', fn ($q) => $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                            $p->where('nom', 'LIKE', $like)
                                ->orWhere('prenom', 'LIKE', $like)
                                ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                            if ($likeTel) {
                                $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                            }
                        }))
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'livreur')
                        ->whereHas('livreurBeneficiaire', function ($q) use ($like, $likeTel, $norm) {
                            $q->where('livreurs.nom_complet', 'LIKE', $like)
                                ->orWhereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                                    $p->where('nom', 'LIKE', $like)
                                        ->orWhere('prenom', 'LIKE', $like)
                                        ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                                    if ($likeTel) {
                                        $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                                    }
                                });
                        })
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'proprietaire')
                        ->whereHas('proprietaireBeneficiaire', fn ($q) => $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                            $p->where('nom', 'LIKE', $like)
                                ->orWhere('prenom', 'LIKE', $like)
                                ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                            if ($likeTel) {
                                $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                            }
                        }))
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'prestataire')
                        ->whereHas('prestataireBeneficiaire', $this->matchPrestataireIdentite($like, $likeTel, $norm))
                    )->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'client')
                        ->whereHas('clientBeneficiaire', fn ($q) => $q
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                            ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like])
                            ->when($likeTel, fn ($query) => $query->orWhereRaw("{$norm} LIKE ?", [$likeTel]))
                        )
                    );
            });
        }

        if (! empty($filters['concerne'])) {
            $like = '%'.$filters['concerne'].'%';
            $digits = preg_replace('/\D/', '', $filters['concerne']);
            $likeTel = $digits ? '%'.$digits.'%' : null;
            $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";

            $query->where(function ($w) use ($like, $likeTel, $norm) {
                $matchPersonne = function ($q) use ($like, $likeTel, $norm) {
                    $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                        $p->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                            ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                        if ($likeTel) {
                            $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                        }
                    });
                };

                $w->where(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'employe')
                    ->whereHas('employeBeneficiaire', $matchPersonne)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'livreur')
                    ->whereHas('livreurBeneficiaire', fn ($q) => $q
                        ->where('livreurs.nom_complet', 'LIKE', $like)
                        ->orWhere($matchPersonne))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'proprietaire')
                    ->whereHas('proprietaireBeneficiaire', $matchPersonne)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'vehicule')
                    ->whereHas('vehiculeBeneficiaire', fn ($q) => $q->where('nom_vehicule', 'LIKE', $like))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'prestataire')
                    ->whereHas('prestataireBeneficiaire', $this->matchPrestataireIdentite($like, $likeTel, $norm))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'client')
                    ->whereHas('clientBeneficiaire', fn ($q) => $q
                        ->where('nom', 'LIKE', $like)
                        ->orWhere('prenom', 'LIKE', $like)
                        ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like])
                        ->when($likeTel, fn ($query) => $query->orWhereRaw("{$norm} LIKE ?", [$likeTel]))
                    )
                );
            });
        }

        if (! empty($filters['telephone_concerne'])) {
            $digits = preg_replace('/\D/', '', $filters['telephone_concerne']);
            $likeTel = '%'.($digits ?: $filters['telephone_concerne']).'%';
            $query->where(function ($w) use ($likeTel) {
                $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";
                $matchTel = fn ($q) => $q->whereHas('personne', fn ($p) => $p->whereRaw("{$norm} LIKE ?", [$likeTel]));

                $w->where(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'employe')
                    ->whereHas('employeBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'livreur')
                    ->whereHas('livreurBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'proprietaire')
                    ->whereHas('proprietaireBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'prestataire')
                    ->whereHas('prestataireBeneficiaire', fn ($q) => $q
                        ->whereHas('personne', fn ($p) => $p->whereRaw("{$norm} LIKE ?", [$likeTel]))
                        ->orWhereHas('entrepriseTierce', fn ($e) => $e->whereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel]))
                    )
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'client')
                    ->whereHas('clientBeneficiaire', fn ($q) => $q->whereRaw("{$norm} LIKE ?", [$likeTel]))
                );
            });
        }

        if (isset($filters['montant']) && $filters['montant'] !== '') {
            $query->where('montant', (float) $filters['montant']);
        }

        return $query;
    }

    /**
     * Un Prestataire porte son identité soit via Personne (physique) soit via EntrepriseTierce
     * (morale) — jamais les deux, cf. Prestataire::$appends. Les recherches "concerne"/"search"
     * doivent matcher les deux chemins, contrairement à employe/livreur/proprietaire qui n'ont
     * qu'une seule identité possible (Personne).
     */
    private function matchPrestataireIdentite(string $like, ?string $likeTel, string $norm): \Closure
    {
        return function ($q) use ($like, $likeTel, $norm) {
            $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                $p->where('nom', 'LIKE', $like)
                    ->orWhere('prenom', 'LIKE', $like)
                    ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                if ($likeTel) {
                    $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                }
            })->orWhereHas('entrepriseTierce', function ($e) use ($like, $likeTel) {
                $e->where('raison_sociale', 'LIKE', $like);
                if ($likeTel) {
                    $e->orWhereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel]);
                }
            });
        };
    }

    private function transformDepense(Depense $d, array $labelCache, array $vehiculeInfoCache, ?User $user = null, ?DroitCreationDepense $droitValidation = null): array
    {
        $categorie = $d->depenseType?->categorie;
        $cacheKey = "{$d->beneficiaire_type}:{$d->beneficiaire_id}";

        $vehiculeNom = null;
        $concerneReelLabel = $labelCache[$cacheKey] ?? null;
        $impactMessage = $categorie?->impactMessage() ?? '';

        if ($d->beneficiaire_type === 'vehicule' && $d->beneficiaire_id) {
            $vehiculeNom = $labelCache[$cacheKey] ?? null;
            $extra = $vehiculeInfoCache[$d->beneficiaire_id] ?? null;
            if ($extra) {
                $concerneReelLabel = $extra['concerne_reel_label'];
                $impactMessage = $extra['impact_message'];
            }
        }

        $beneficiaireTelephone = null;
        $vehiculeImmatriculation = null;

        if ($d->beneficiaire_type === 'vehicule' && $d->beneficiaire_id) {
            $extra = $vehiculeInfoCache[$d->beneficiaire_id] ?? null;
            if ($extra) {
                $vehiculeImmatriculation = $extra['immatriculation'] ?? null;
                $beneficiaireTelephone = $extra['telephone'] ?? null;
            }
        } else {
            $beneficiaireTelephone = $labelCache["tel:{$d->beneficiaire_type}:{$d->beneficiaire_id}"] ?? null;
        }

        return [
            'id' => $d->id,
            'montant' => (float) $d->montant,
            'date_depense' => $d->date_depense->toDateString(),
            'statut' => $d->statut->value,
            'statut_label' => $d->statut->label(),
            'commentaire' => $d->commentaire,
            'type' => $d->depenseType ? [
                'id' => $d->depenseType->id,
                'libelle' => $d->depenseType->libelle,
                'categorie' => $categorie?->value,
                'categorie_label' => $categorie?->label(),
                'impact_message' => $impactMessage,
                'commentaire_obligatoire' => $d->depenseType->commentaire_obligatoire,
                'justificatif_obligatoire' => $d->depenseType->justificatif_obligatoire,
            ] : null,
            'beneficiaire_type' => $d->beneficiaire_type,
            'beneficiaire_id' => $d->beneficiaire_id,
            'beneficiaire_label' => $concerneReelLabel,
            'beneficiaire_telephone' => $beneficiaireTelephone,
            'vehicule_nom' => $vehiculeNom,
            'vehicule_id' => ($d->beneficiaire_type === 'vehicule') ? $d->beneficiaire_id : null,
            'vehicule_immatriculation' => $vehiculeImmatriculation,
            'site' => $d->site ? ['id' => $d->site->id, 'nom' => $d->site->nom] : null,
            'user' => ['id' => $d->user->id, 'name' => $d->user->name],
            'validateur' => $d->validateur ? ['id' => $d->validateur->id, 'name' => $d->validateur->name] : null,
            'can_valider' => $user && $d->statut === StatutDepense::SOUMIS
                && $this->droitCreationDepense->peutValiderSurSite($user, $droitValidation, $d->site_id),
        ];
    }

    private function preloadBeneficiaires(array $depenses): array
    {
        $labelCache = [];
        $vehiculeInfoCache = [];

        $byType = collect($depenses)
            ->filter(fn ($d) => $d->beneficiaire_type && $d->beneficiaire_id)
            ->groupBy('beneficiaire_type');

        foreach ($byType as $type => $items) {
            $ids = $items->pluck('beneficiaire_id')->unique()->values()->all();

            if ($type === 'vehicule') {
                $models = Vehicule::with(['proprietaire:id,personne_id', 'proprietaire.personne'])
                    ->findMany($ids, ['id', 'nom_vehicule', 'immatriculation', 'proprietaire_id']);

                foreach ($models as $model) {
                    $labelCache["vehicule:{$model->id}"] = $model->nom_vehicule;

                    if ($model->proprietaire_id) {
                        $propNom = trim("{$model->proprietaire->prenom} {$model->proprietaire->nom}");
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => $propNom,
                            'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
                            'immatriculation' => $model->immatriculation,
                            'telephone' => $model->proprietaire->telephone,
                        ];
                    } else {
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => 'Agence ELM',
                            'impact_message' => 'Ce véhicule est interne ELM. La dépense sera comptabilisée comme charge entreprise.',
                            'immatriculation' => $model->immatriculation,
                            'telephone' => null,
                        ];
                    }
                }
            } else {
                // Identité civile portée par Personne (nom/prenom/telephone) — nom_complet
                // reste une colonne propre au livreur, utilisée en repli par libelleAffichage().
                // Prestataire est à part : identité physique (Personne) OU morale
                // (EntrepriseTierce), jamais une colonne directe — d'où fields=null (toutes
                // colonnes) et les accesseurs nom_complet/phone plutôt que nom/prenom/telephone.
                $fields = match (true) {
                    $type === 'livreur' => ['id', 'personne_id', 'nom_complet'],
                    $type === 'prestataire' => null,
                    $type === 'client' => ['id', 'nom', 'prenom', 'nom_complet', 'telephone'],
                    default => ['id', 'personne_id'],
                };

                $models = match ($type) {
                    'employe' => Employe::with('personne')->findMany($ids, $fields),
                    'livreur' => Livreur::with('personne')->findMany($ids, $fields),
                    'proprietaire' => Proprietaire::with('personne')->findMany($ids, $fields),
                    'prestataire' => Prestataire::with(['personne', 'entrepriseTierce'])->findMany($ids),
                    'client' => Client::findMany($ids, $fields),
                    default => collect(),
                };

                foreach ($models as $model) {
                    if ($type === 'prestataire') {
                        $labelCache["prestataire:{$model->id}"] = $model->nom_complet;
                        $labelCache["tel:prestataire:{$model->id}"] = $model->phone;

                        continue;
                    }

                    if ($type === 'client') {
                        $labelCache["client:{$model->id}"] = $model->nom_complet;
                        $labelCache["tel:client:{$model->id}"] = $model->telephone;

                        continue;
                    }

                    $labelCache["{$type}:{$model->id}"] = $type === 'livreur'
                        ? $model->libelleAffichage()
                        : trim("{$model->prenom} {$model->nom}");
                    $labelCache["tel:{$type}:{$model->id}"] = $model->telephone ?? null;
                }
            }
        }

        return [$labelCache, $vehiculeInfoCache];
    }

    private function resolveVehiculeInfo(string $vehiculeId): array
    {
        $vehicule = Vehicule::with(['proprietaire:id,personne_id', 'proprietaire.personne'])
            ->find($vehiculeId, ['id', 'nom_vehicule', 'immatriculation', 'proprietaire_id']);

        if (! $vehicule) {
            return ['vehicule_nom' => null, 'vehicule_immatriculation' => null, 'concerne_reel_label' => null, 'impact_message' => ''];
        }

        $vehiculeNom = $vehicule->nom_vehicule;
        $immatriculation = $vehicule->immatriculation;

        if ($vehicule->proprietaire_id) {
            $propNom = trim("{$vehicule->proprietaire->prenom} {$vehicule->proprietaire->nom}");

            return [
                'vehicule_nom' => $vehiculeNom,
                'vehicule_immatriculation' => $immatriculation,
                'concerne_reel_label' => $propNom,
                'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
            ];
        }

        return [
            'vehicule_nom' => $vehiculeNom,
            'vehicule_immatriculation' => $immatriculation,
            'concerne_reel_label' => 'Agence ELM',
            'impact_message' => 'Ce véhicule est interne ELM. La dépense sera comptabilisée comme charge entreprise.',
        ];
    }

    private function resoudreBeneficiaireLabel(?string $type, ?string $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            'employe' => optional(Employe::find($id))->nom_complet,
            'livreur' => ($l = Livreur::find($id)) ? ($l->nom_complet ?? $l->telephone) : null,
            'proprietaire' => trim(optional(Proprietaire::find($id))?->prenom.' '.optional(Proprietaire::find($id))?->nom),
            'vehicule' => optional(Vehicule::find($id))->nom_vehicule,
            'prestataire' => optional(Prestataire::find($id))->nom_complet,
            'client' => optional(Client::find($id))->nom_complet,
            default => null,
        };
    }

    private function loadTypes(string $orgId)
    {
        return DepenseType::where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'libelle', 'categorie', 'commentaire_obligatoire', 'justificatif_obligatoire'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'libelle' => $t->libelle,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'impact_message' => $t->categorie->impactMessage(),
                'commentaire_obligatoire' => $t->commentaire_obligatoire,
                'justificatif_obligatoire' => $t->justificatif_obligatoire,
            ]);
    }

    private function loadVehicules(string $orgId)
    {
        return Vehicule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->with(['site:id,nom', 'proprietaire:id,personne_id', 'proprietaire.personne'])
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation', 'categorie', 'site_id', 'proprietaire_id'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                // Propriété réelle du véhicule — plus jamais reconstruite depuis
                // livraison_logistique (confusion usage/propriété corrigée, cf. Vehicule::categorie).
                // Consommé par Depenses/Create.vue et Edit.vue (vehiculeContext) pour distinguer
                // "ELM" d'un propriétaire tiers dans le picker.
                'categorie' => $v->categorie->value,
                'site_nom' => $v->site?->nom,
                'proprietaire_nom' => $v->proprietaire
                    ? trim("{$v->proprietaire->prenom} {$v->proprietaire->nom}")
                    : null,
                'has_proprietaire' => (bool) $v->proprietaire_id,
            ]);
    }

    private function loadSites(string $orgId)
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    private function loadEmployes(string $orgId)
    {
        return Employe::with('personne')
            ->where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->get(['id', 'personne_id', 'matricule', 'site_id'])
            ->sortBy('nom')
            ->map(fn ($e) => [
                'id' => $e->id,
                'nom_complet' => trim("{$e->prenom} {$e->nom}"),
                'matricule' => $e->matricule,
                'telephone' => $e->telephone,
                'site_nom' => $e->site?->nom,
            ])
            ->values();
    }

    private function loadLivreurs(string $orgId)
    {
        return Livreur::with(['personne', 'equipes.vehicule'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_complet')
            ->get(['id', 'personne_id', 'nom_complet'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'nom_complet' => $l->libelleAffichage(),
                'telephone' => $l->telephone,
                // Permet de retrouver un livreur par le nom/l'immatriculation du véhicule
                // auquel il est rattaché (Depenses/Create.vue et Edit.vue), en plus de son
                // propre nom/téléphone — deux champs distincts, pas un libellé combiné, pour
                // que le picker propose un champ de recherche dédié par critère.
                ...$this->vehiculeInfoDepuis($l->equipes->pluck('vehicule')),
            ]);
    }

    private function loadProprietaires(string $orgId)
    {
        return Proprietaire::with(['personne', 'vehicules'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get(['id', 'personne_id'])
            ->sortBy('nom')
            ->map(fn ($p) => [
                'id' => $p->id,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
                'telephone' => $p->telephone,
                // Idem : retrouver un propriétaire par le nom/l'immatriculation d'un véhicule
                // qui lui appartient — deux champs distincts (cf. loadLivreurs()).
                ...$this->vehiculeInfoDepuis($p->vehicules),
            ])
            ->values();
    }

    private function loadPrestataires(string $orgId)
    {
        return Prestataire::with(['personne', 'entrepriseTierce'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get()
            ->sortBy('nom_complet')
            ->map(fn (Prestataire $p) => [
                'id' => $p->id,
                'nom_complet' => $p->nom_complet,
                'telephone' => $p->phone,
            ])
            ->values();
    }

    private function loadClients(string $orgId)
    {
        return Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get(['id', 'nom', 'prenom', 'nom_complet', 'telephone'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'nom_complet' => $client->nom_complet,
                'telephone' => $client->telephone,
            ])
            ->values();
    }

    /**
     * Noms et immatriculations des véhicules de la collection, chacun joint par ", " —
     * deux champs distincts (pas un libellé combiné) pour que le picker propose un champ de
     * recherche dédié par critère (cf. loadLivreurs()/loadProprietaires()).
     *
     * @return array{vehicule_noms: ?string, vehicule_immatriculations: ?string}
     */
    private function vehiculeInfoDepuis(Collection $vehicules): array
    {
        $vehicules = $vehicules->filter()->unique('id');

        return [
            'vehicule_noms' => $vehicules->pluck('nom_vehicule')->filter()->implode(', ') ?: null,
            'vehicule_immatriculations' => $vehicules->pluck('immatriculation')->filter()->implode(', ') ?: null,
        ];
    }

    private function defaultSiteId(): ?string
    {
        return auth()->user()->sites()
            ->wherePivot('is_default', true)
            ->select('sites.id')
            ->first()?->id;
    }

    private function buildAuditDescription(AuditLog $log): string
    {
        return match ($log->event_code) {
            'created' => 'Dépense créée',
            'updated' => $this->describeUpdate($log),
            'submitted' => 'Soumise pour validation',
            'validated' => 'Dépense validée et imputée',
            'rejected' => 'Rejetée — Motif : '.($log->meta['motif_rejet'] ?? 'non précisé'),
            'deleted' => 'Dépense supprimée',
            'exported' => 'Export '.($log->meta['format'] ?? ''),
            default => $log->event_label,
        };
    }

    private function describeUpdate(AuditLog $log): string
    {
        $newValues = $log->new_values ?? [];
        if (empty($newValues)) {
            return 'Dépense modifiée';
        }

        $labels = [
            'montant' => 'Montant',
            'date_depense' => 'Date',
            'commentaire' => 'Commentaire',
            'statut' => 'Statut',
            'site_id' => 'Site',
            'depense_type_id' => 'Type',
            'beneficiaire_id' => 'Bénéficiaire',
            'motif_rejet' => 'Motif de rejet',
        ];

        $changed = array_values(array_filter(
            array_map(fn ($k) => $labels[$k] ?? null, array_keys($newValues))
        ));

        return empty($changed)
            ? 'Dépense modifiée'
            : 'Modifié : '.implode(', ', $changed);
    }
}
