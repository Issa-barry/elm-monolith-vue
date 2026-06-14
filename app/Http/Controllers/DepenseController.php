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
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\DepenseImputationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepenseController extends Controller
{
    public function __construct(private DepenseImputationService $imputationService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;

        $query = Depense::with(['depenseType', 'site', 'user'])
            ->forOrg($orgId)
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('depense_type_id', $request->type);
        }
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->filled('categorie')) {
            $query->whereHas('depenseType', fn ($q) => $q->where('categorie', $request->categorie));
        }
        if ($request->filled('beneficiaire_type')) {
            $query->where('beneficiaire_type', $request->beneficiaire_type);
        }
        if ($request->filled('site')) {
            $query->where('site_id', $request->site);
        }
        if ($request->filled('date_debut')) {
            $query->where('date_depense', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->where('date_depense', '<=', $request->date_fin);
        }

        $paginator = $query->paginate(30)->withQueryString();

        $beneficiaireCache = $this->preloadBeneficiaires($paginator->items());

        $types = DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get(['id', 'libelle', 'categorie']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        return Inertia::render('Depenses/Index', [
            'depenses' => $paginator->through(fn (Depense $d) => $this->transformDepense($d, $beneficiaireCache)),
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->libelle, 'categorie' => $t->categorie->value]),
            'sites' => $sites,
            'categories' => CategorieDepense::options(),
            'statuts' => StatutDepense::options(),
            'filters' => $request->only(['type', 'statut', 'categorie', 'beneficiaire_type', 'site', 'date_debut', 'date_fin']),
        ]);
    }

    public function show(Depense $depense): Response
    {
        $this->authorize('view', $depense);

        $depense->load(['depenseType', 'site', 'user', 'validateur', 'imputations']);

        $categorie = $depense->depenseType?->categorie;
        $beneficiaireLabel = $this->resoudreBeneficiaireLabel(
            $depense->beneficiaire_type,
            $depense->beneficiaire_id
        );

        $user = auth()->user();

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
                'justificatif_path' => $depense->justificatif_path,
                'date_validation' => $depense->date_validation?->toDateTimeString(),
                'created_at' => $depense->created_at->toDateTimeString(),
                'type_libelle' => $depense->depenseType?->libelle ?? '—',
                'type_code' => $depense->depenseType?->code ?? '—',
                'categorie' => $categorie?->value ?? '',
                'categorie_label' => $categorie?->label() ?? '',
                'impact_message' => $categorie?->impactMessage() ?? '',
                'beneficiaire_label' => $beneficiaireLabel,
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
                'can_impute' => $user->can('imputer', $depense) && $depense->statut->value === 'valide',
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

        $depense->update([
            'statut' => StatutDepense::VALIDE,
            'validateur_id' => auth()->id(),
            'date_validation' => now(),
            'motif_rejet' => null,
        ]);

        return back()->with('success', 'Dépense validée.');
    }

    public function rejeter(Request $request, Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être rejetées.']);
        }

        $validated = $request->validate([
            'motif_rejet' => ['required', 'string', 'max:1000'],
        ], [
            'motif_rejet.required' => 'Le motif de rejet est obligatoire.',
        ]);

        $depense->update([
            'statut' => StatutDepense::REJETE,
            'validateur_id' => auth()->id(),
            'date_validation' => now(),
            'motif_rejet' => $validated['motif_rejet'],
        ]);

        return back()->with('success', 'Dépense rejetée.');
    }

    public function imputer(Depense $depense): RedirectResponse
    {
        $this->authorize('imputer', $depense);

        if ($depense->statut !== StatutDepense::VALIDE) {
            return back()->withErrors(['statut' => 'Seules les dépenses validées peuvent être imputées.']);
        }

        $depense->load('depenseType');

        try {
            $this->imputationService->creer($depense);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['imputation' => $e->getMessage()]);
        }

        $depense->update(['statut' => StatutDepense::IMPUTE]);

        return back()->with('success', 'Dépense imputée avec succès.');
    }

    public function destroy(Depense $depense): RedirectResponse
    {
        $this->authorize('delete', $depense);

        $depense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function transformDepense(Depense $d, array $beneficiaireCache): array
    {
        $categorie = $d->depenseType?->categorie;
        $cacheKey = "{$d->beneficiaire_type}:{$d->beneficiaire_id}";

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
                'categorie_concerne' => $categorie?->labelConcerne(),
                'impact_message' => $categorie?->impactMessage(),
                'commentaire_obligatoire' => $d->depenseType->commentaire_obligatoire,
                'justificatif_obligatoire' => $d->depenseType->justificatif_obligatoire,
            ] : null,
            'beneficiaire_type' => $d->beneficiaire_type,
            'beneficiaire_label' => $beneficiaireCache[$cacheKey] ?? null,
            'site' => $d->site ? ['id' => $d->site->id, 'nom' => $d->site->nom] : null,
            'user' => ['id' => $d->user->id, 'name' => $d->user->name],
        ];
    }

    private function preloadBeneficiaires(array $depenses): array
    {
        $cache = [];
        $byType = collect($depenses)
            ->filter(fn ($d) => $d->beneficiaire_type && $d->beneficiaire_id)
            ->groupBy('beneficiaire_type');

        foreach ($byType as $type => $items) {
            $ids = $items->pluck('beneficiaire_id')->unique()->values()->all();

            $models = match ($type) {
                'employe' => Employe::findMany($ids, ['id', 'nom', 'prenom']),
                'livreur' => Livreur::findMany($ids, ['id', 'nom', 'prenom']),
                'proprietaire' => Proprietaire::findMany($ids, ['id', 'nom', 'prenom']),
                'vehicule' => Vehicule::findMany($ids, ['id', 'nom_vehicule']),
                default => collect(),
            };

            foreach ($models as $model) {
                $label = $type === 'vehicule'
                    ? $model->nom_vehicule
                    : trim("{$model->prenom} {$model->nom}");

                $cache["{$type}:{$model->id}"] = $label;
            }
        }

        return $cache;
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
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation', 'proprietaire_id'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
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
