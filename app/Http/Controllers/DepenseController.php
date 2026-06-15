<?php

namespace App\Http\Controllers;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Http\Requests\StoreDepenseRequest;
use App\Http\Requests\UpdateDepenseRequest;
use App\Models\Depense;
use App\Models\DepenseImputation;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\DepenseImputationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepenseController extends Controller
{
    public function __construct(private DepenseImputationService $imputationService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'site', 'date_debut', 'date_fin']);

        $paginator = $this->buildQuery($filters, $orgId)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->paginate(30)
            ->withQueryString();

        [$beneficiaireCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($paginator->items());

        $types = DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get(['id', 'libelle', 'categorie']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        return Inertia::render('Depenses/Index', [
            'depenses' => $paginator->through(fn (Depense $d) => $this->transformDepense($d, $beneficiaireCache, $vehiculeInfoCache)),
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->libelle, 'categorie' => $t->categorie->value]),
            'sites' => $sites,
            'categories' => CategorieDepense::options(),
            'statuts' => StatutDepense::options(),
            'filters' => $filters,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'site', 'date_debut', 'date_fin']);

        $depenses = $this->buildQuery($filters, $orgId)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($depenses->all());

        $filename = 'depenses-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($depenses, $labelCache, $vehiculeInfoCache) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Référence', 'Date', 'Type', 'Catégorie', 'Concerné', 'Véhicule',
                'Montant (GNF)', 'Statut', 'Site', 'Saisi par', 'Validé par', 'Commentaire',
            ], ';');

            foreach ($depenses as $d) {
                $row = $this->transformDepense($d, $labelCache, $vehiculeInfoCache);
                fputcsv($handle, [
                    $d->id,
                    $row['date_depense'],
                    $row['type']['libelle'] ?? '',
                    $row['type']['categorie_label'] ?? '',
                    $row['beneficiaire_label'] ?? '',
                    $row['vehicule_nom'] ?? '',
                    number_format((float) $row['montant'], 0, ',', ' '),
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

    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'site', 'date_debut', 'date_fin']);
        $org = Organization::find($orgId);
        $printedBy = auth()->user()->name;
        $now = now();
        $dateStr = $now->format('dmY');

        $depenses = $this->buildQuery($filters, $orgId)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->preloadBeneficiaires($depenses->all());

        $rows = $depenses->map(fn (Depense $d) => $this->transformDepense($d, $labelCache, $vehiculeInfoCache));

        // Filtre site actif → un seul PDF pour ce site
        if (! empty($filters['site'])) {
            $site = Site::find($filters['site']);
            $slug = $this->slugifySite($site?->nom ?? 'site');
            $pdf = $this->buildSitePdf($rows, $org, $site, $filters, $printedBy, $now);

            return $pdf->download("depenses-{$slug}-{$dateStr}.pdf");
        }

        // Sans filtre site → PDF multi-sites avec saut de page entre chaque site
        $grouped = $rows->groupBy(fn ($row) => $row['site']['id'] ?? 'sans-site');

        $sites = $grouped->map(fn ($siteRows) => [
            'site_nom' => $siteRows->first()['site']['nom'] ?? null,
            'rows' => $siteRows,
            'total' => $siteRows->sum('montant'),
        ])->values();

        return $this->buildMultiSitePdf($sites, $org, $filters, $printedBy, $now)
            ->download("depenses-{$dateStr}.pdf");
    }

    public function imprimer(Request $request): \Illuminate\Http\Response
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'site', 'date_debut', 'date_fin']);
        $org = Organization::find($orgId);
        $printedBy = auth()->user()->name;
        $now = now();

        $depenses = $this->buildQuery($filters, $orgId)
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

    private function buildSitePdf(
        Collection $rows,
        ?Organization $org,
        ?Site $site,
        array $filters,
        string $printedBy,
        Carbon $generatedAt,
    ): \Barryvdh\DomPDF\PDF {
        return Pdf::loadView('pdf.depenses', [
            'rows' => $rows,
            'total' => $rows->sum('montant'),
            'filters' => $filters,
            'org' => $org,
            'site_nom' => $site?->nom,
            'printed_by' => $printedBy,
            'generated_at' => $generatedAt,
        ])->setPaper('a4', 'landscape');
    }

    private function buildMultiSitePdf(
        Collection $sites,
        ?Organization $org,
        array $filters,
        string $printedBy,
        Carbon $generatedAt,
    ): \Barryvdh\DomPDF\PDF {
        return Pdf::loadView('pdf.depenses_multi', [
            'sites' => $sites,
            'filters' => $filters,
            'org' => $org,
            'printed_by' => $printedBy,
            'generated_at' => $generatedAt,
        ])->setPaper('a4', 'landscape');
    }

    private function slugifySite(string $nom): string
    {
        return Str::slug($nom);
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

    public function create(): Response
    {
        $this->authorize('create', Depense::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Depenses/Create', [
            'types' => $this->loadTypes($orgId),
            'vehicules' => $this->loadVehicules($orgId),
            'sites' => $this->loadSites($orgId),
            'employes' => $this->loadEmployes($orgId),
            'livreurs' => $this->loadLivreurs($orgId),
            'proprietaires' => $this->loadProprietaires($orgId),
            'default_site_id' => $this->defaultSiteId(),
            'categories' => CategorieDepense::optionsConcerne(),
        ]);
    }

    public function store(StoreDepenseRequest $request): RedirectResponse
    {
        $orgId = auth()->user()->organization_id;
        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        Depense::create([
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

        return redirect()->route('depenses.index')->with('success', 'Dépense enregistrée.');
    }

    public function edit(Depense $depense): Response
    {
        $this->authorize('update', $depense);

        $orgId = auth()->user()->organization_id;

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
            'sites' => $this->loadSites($orgId),
            'employes' => $this->loadEmployes($orgId),
            'livreurs' => $this->loadLivreurs($orgId),
            'proprietaires' => $this->loadProprietaires($orgId),
            'categories' => CategorieDepense::optionsConcerne(),
        ]);
    }

    public function update(UpdateDepenseRequest $request, Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        $orgId = auth()->user()->organization_id;
        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        $depense->update([
            'depense_type_id' => $request->depense_type_id,
            'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
            'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
            'site_id' => $request->site_id,
            'montant' => $request->montant,
            'date_depense' => $request->date_depense,
            'commentaire' => $request->commentaire,
        ]);

        return redirect()->route('depenses.show', $depense)->with('success', 'Dépense mise à jour.');
    }

    public function soumettre(Depense $depense): RedirectResponse
    {
        $this->authorize('view', $depense);

        if ($depense->statut !== StatutDepense::BROUILLON) {
            return back()->withErrors(['statut' => 'Seules les dépenses en brouillon peuvent être soumises.']);
        }

        $depense->update(['statut' => StatutDepense::SOUMIS]);

        return back()->with('success', 'Dépense soumise pour validation.');
    }

    public function valider(Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être validées.']);
        }

        $depense->load('depenseType');

        try {
            DB::transaction(function () use ($depense) {
                $depense->update([
                    'statut' => StatutDepense::VALIDE,
                    'validateur_id' => auth()->id(),
                    'date_validation' => now(),
                    'motif_rejet' => null,
                ]);

                $this->imputationService->creer($depense);
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['imputation' => $e->getMessage()]);
        }

        return back()->with('success', 'Dépense validée et imputée.');
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

        return back()->with('success', 'Dépense rejetée.');
    }

    public function destroy(Depense $depense): RedirectResponse
    {
        $this->authorize('delete', $depense);

        $depense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildQuery(array $filters, string $orgId): Builder
    {
        $query = Depense::forOrg($orgId)
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $like = '%'.$filters['search'].'%';
            $query->where(function ($w) use ($like) {
                $w->where('commentaire', 'LIKE', $like)
                    ->orWhereHas('depenseType', fn ($q) => $q->where('libelle', 'LIKE', $like))
                    ->orWhereHas('site', fn ($q) => $q->where('nom', 'LIKE', $like))
                    ->orWhereHas('user', fn ($q) => $q->where('name', 'LIKE', $like))
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'vehicule')
                        ->whereHas('vehiculeBeneficiaire', fn ($q) => $q
                            ->where('nom_vehicule', 'LIKE', $like)
                            ->orWhereHas('proprietaire', fn ($q) => $q
                                ->where('nom', 'LIKE', $like)
                                ->orWhere('prenom', 'LIKE', $like)
                            )
                        )
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'employe')
                        ->whereHas('employeBeneficiaire', fn ($q) => $q
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                        )
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'livreur')
                        ->whereHas('livreurBeneficiaire', fn ($q) => $q
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                        )
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'proprietaire')
                        ->whereHas('proprietaireBeneficiaire', fn ($q) => $q
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                        )
                    );
            });
        }

        if (! empty($filters['type'])) {
            $query->where('depense_type_id', $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (! empty($filters['categorie'])) {
            $query->whereHas('depenseType', fn ($q) => $q->where('categorie', $filters['categorie']));
        }
        if (! empty($filters['site'])) {
            $query->where('site_id', $filters['site']);
        }
        if (! empty($filters['date_debut'])) {
            $query->where('date_depense', '>=', $filters['date_debut']);
        }
        if (! empty($filters['date_fin'])) {
            $query->where('date_depense', '<=', $filters['date_fin']);
        }

        return $query;
    }

    private function transformDepense(Depense $d, array $labelCache, array $vehiculeInfoCache): array
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
            'beneficiaire_label' => $concerneReelLabel,
            'vehicule_nom' => $vehiculeNom,
            'site' => $d->site ? ['id' => $d->site->id, 'nom' => $d->site->nom] : null,
            'user' => ['id' => $d->user->id, 'name' => $d->user->name],
            'validateur' => $d->validateur ? ['id' => $d->validateur->id, 'name' => $d->validateur->name] : null,
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
                $models = Vehicule::with('proprietaire:id,nom,prenom')
                    ->findMany($ids, ['id', 'nom_vehicule', 'proprietaire_id']);

                foreach ($models as $model) {
                    $labelCache["vehicule:{$model->id}"] = $model->nom_vehicule;

                    if ($model->proprietaire_id) {
                        $propNom = trim("{$model->proprietaire->prenom} {$model->proprietaire->nom}");
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => $propNom,
                            'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
                        ];
                    } else {
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => 'Agence ELM',
                            'impact_message' => 'Ce véhicule est interne ELM. La dépense sera comptabilisée comme charge entreprise.',
                        ];
                    }
                }
            } else {
                $models = match ($type) {
                    'employe' => Employe::findMany($ids, ['id', 'nom', 'prenom']),
                    'livreur' => Livreur::findMany($ids, ['id', 'nom', 'prenom']),
                    'proprietaire' => Proprietaire::findMany($ids, ['id', 'nom', 'prenom']),
                    default => collect(),
                };

                foreach ($models as $model) {
                    $labelCache["{$type}:{$model->id}"] = trim("{$model->prenom} {$model->nom}");
                }
            }
        }

        return [$labelCache, $vehiculeInfoCache];
    }

    private function resolveVehiculeInfo(string $vehiculeId): array
    {
        $vehicule = Vehicule::with('proprietaire:id,nom,prenom')
            ->find($vehiculeId, ['id', 'nom_vehicule', 'proprietaire_id']);

        if (! $vehicule) {
            return ['vehicule_nom' => null, 'concerne_reel_label' => null, 'impact_message' => ''];
        }

        $vehiculeNom = $vehicule->nom_vehicule;

        if ($vehicule->proprietaire_id) {
            $propNom = trim("{$vehicule->proprietaire->prenom} {$vehicule->proprietaire->nom}");

            return [
                'vehicule_nom' => $vehiculeNom,
                'concerne_reel_label' => $propNom,
                'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
            ];
        }

        return [
            'vehicule_nom' => $vehiculeNom,
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
            'livreur' => optional(Livreur::find($id))->nom_complet,
            'proprietaire' => trim(optional(Proprietaire::find($id))?->prenom.' '.optional(Proprietaire::find($id))?->nom),
            'vehicule' => optional(Vehicule::find($id))->nom_vehicule,
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
            ->with(['site:id,nom', 'proprietaire:id,nom,prenom'])
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation', 'categorie', 'site_id', 'proprietaire_id'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'categorie' => $v->categorie,
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
        return Employe::where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'matricule'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'nom_complet' => trim("{$e->prenom} {$e->nom}"),
                'matricule' => $e->matricule,
            ]);
    }

    private function loadLivreurs(string $orgId)
    {
        return Livreur::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'nom_complet' => trim("{$l->prenom} {$l->nom}"),
            ]);
    }

    private function loadProprietaires(string $orgId)
    {
        return Proprietaire::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
            ]);
    }

    private function defaultSiteId(): ?string
    {
        return auth()->user()->sites()
            ->wherePivot('is_default', true)
            ->select('sites.id')
            ->first()?->id;
    }
}
