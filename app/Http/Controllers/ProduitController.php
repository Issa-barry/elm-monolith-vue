<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementStock;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\AuditLog;
use App\Models\Categorie;
use App\Models\MouvementStock;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\AuditLogService;
use App\Services\DroitAjustementStockService;
use App\Services\MediaService;
use App\Services\ProduitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly DroitAjustementStockService $droitService,
        private readonly ProduitService $produitService,
        private readonly MediaService $mediaService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Produit::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $filters = $request->only(['search', 'type', 'statut', 'categorie_id']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        if (empty($siteIds) && ! $isAdmin) {
            $siteIds = $user->sites()->pluck('sites.id')->map(fn ($id) => (string) $id)->toArray();
        }

        $query = Produit::where('organization_id', $orgId)
            ->with([
                'categorie:id,nom',
                'variantes' => fn ($q) => $q->orderBy('position'),
                // Pas de limit() ici : une contrainte LIMIT sur un eager load hasMany s'applique
                // à l'ensemble du résultat, pas par produit. getImageUrlAttribute() prend le
                // premier (is_primary puis position) dans la collection déjà chargée.
                'medias' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
            ])
            ->orderBy('nom');

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('nom', 'like', "%{$s}%")
                ->orWhereHas('variantes', fn ($vq) => $vq->where('code_interne', 'like', "%{$s}%")));
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (! empty($filters['categorie_id'])) {
            $query->where('categorie_id', $filters['categorie_id']);
        }

        $produits = $query->get();
        $varianteIds = $produits->flatMap(fn (Produit $p) => $p->variantes->pluck('id'))->all();

        $allVarianteStocks = VarianteStock::whereIn('produit_variante_id', $varianteIds)
            ->with('site:id,nom,code')
            ->get()
            ->groupBy('produit_variante_id');

        $usedVarianteIds = collect()
            ->merge(DB::table('commande_vente_lignes')->whereIn('variante_id', $varianteIds)->pluck('variante_id'))
            ->merge(DB::table('commande_achat_lignes')->whereIn('variante_id', $varianteIds)->whereNotNull('variante_id')->pluck('variante_id'))
            ->unique()->flip()->all();

        $lastMouvementsParVariante = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->when(! empty($siteIds), fn ($q) => $q->whereIn('site_id', $siteIds))
            ->orderByDesc('created_at')
            ->get(['produit_variante_id', 'type', 'quantite', 'created_at'])
            ->groupBy('produit_variante_id')
            ->map(fn ($ms) => $ms->first());

        $mapped = $produits->map(function (Produit $p) use ($allVarianteStocks, $siteIds, $usedVarianteIds, $lastMouvementsParVariante) {
            $varianteIdsProduit = $p->variantes->pluck('id')->all();
            $siteStocks = collect($varianteIdsProduit)->flatMap(fn ($vid) => $allVarianteStocks->get($vid, collect()));
            $variantePrincipale = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
            $hasStock = $p->type?->hasStock() ?? true;

            if (! empty($siteIds)) {
                $filteredStocks = $siteStocks->filter(fn ($s) => in_array((string) $s->site_id, $siteIds, true));
                $qteDisplay = (int) $filteredStocks->sum('qte_stock');
                $seuilDisplay = count($siteIds) === 1
                    ? ($filteredStocks->first()?->seuil_alerte_stock ?? $variantePrincipale?->seuil_alerte_stock)
                    : $variantePrincipale?->seuil_alerte_stock;
            } else {
                $qteDisplay = $siteStocks->isNotEmpty() ? (int) $siteStocks->sum('qte_stock') : (int) ($p->qte_stock ?? 0);
                $seuilDisplay = $variantePrincipale?->seuil_alerte_stock;
            }

            $inStock = ! $hasStock || $qteDisplay > 0;
            $isLowStock = $hasStock && $qteDisplay > 0 && $seuilDisplay !== null && $seuilDisplay > 0 && $qteDisplay <= $seuilDisplay;

            $lastMouvement = collect($varianteIdsProduit)
                ->map(fn ($vid) => $lastMouvementsParVariante->get($vid))
                ->filter()
                ->sortByDesc('created_at')
                ->first();

            $isUsed = collect($varianteIdsProduit)->contains(fn ($vid) => isset($usedVarianteIds[$vid]));

            return [
                'id' => $p->id,
                'nom' => $p->nom,
                'categorie_id' => $p->categorie_id,
                'categorie_nom' => $p->categorie?->nom,
                'code_interne' => $variantePrincipale?->code_interne,
                'code_fournisseur' => $variantePrincipale?->code_fournisseur,
                'type' => $p->type?->value,
                'type_label' => $p->type?->label(),
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut?->label(),
                'image_url' => $p->image_url,
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'description' => $p->description,
                'is_alerte' => $p->is_alerte,
                'qte_stock' => $qteDisplay,
                'seuil_alerte_stock' => $seuilDisplay,
                'has_stock' => $hasStock,
                'in_stock' => $inStock,
                'is_low_stock' => $isLowStock,
                'is_used' => $isUsed,
                'variantes_count' => $p->variantes->count(),
                'has_variantes' => $p->variantes->count() > 1,
                'last_mouvement_type' => $lastMouvement?->type,
                'last_mouvement_quantite' => $lastMouvement?->quantite,
                'stocks_par_site' => $siteStocks->map(fn ($s) => [
                    'site_id' => $s->site_id,
                    'site_code' => $s->site?->code,
                    'site_nom' => $s->site?->nom,
                    'qte_stock' => $s->qte_stock,
                    'seuil_alerte_stock' => $s->seuil_alerte_stock,
                    'is_alerte' => $s->is_alerte,
                    'updated_at' => $s->updated_at?->toISOString(),
                ])->values()->all(),
            ];
        });

        $allSites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);
        $categories = Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']);

        $canAjuster = $this->droitService->canAjuster($user, $orgId);
        $sitesAutorisesRaw = $canAjuster
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $allSites)
            : collect();

        return Inertia::render('Produits/Index', [
            'produits' => $mapped,
            'sites' => $allSites,
            'categories' => $categories,
            'can_ajuster_stock' => $canAjuster,
            'can_augmenter_stock' => $this->droitService->canAugmenter($user, $orgId),
            'can_diminuer_stock' => $this->droitService->canDiminuer($user, $orgId),
            'sites_autorises' => $sitesAutorisesRaw->values(),
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Produit::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Produits/Create', [
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'limites' => $this->limitesCatalogue($orgId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Produit::class);

        $data = $this->validerFormulaire($request);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        // Transaction englobante : si l'upload d'images échoue (limite dépassée), le produit
        // qui vient d'être créé ne doit pas rester orphelin en base.
        $produit = DB::transaction(function () use ($data, $orgId, $request) {
            $produit = $this->produitService->creer([...$data, 'organization_id' => $orgId]);

            if ($request->hasFile('images')) {
                $this->mediaService->ajouter($produit, $request->file('images'));
            }

            return $produit;
        });

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            auth()->user(),
            null,
            $this->produitSnapshot($produit->fresh(['variantes'])),
        );

        return redirect()->route('produits.index')->with('success', 'Produit créé avec succès.');
    }

    public function show(Produit $produit): Response
    {
        $this->authorize('view', $produit);

        $produit->load(['categorie', 'variantes.valeurs', 'medias']);
        $orgId = $produit->organization_id;
        $user = auth()->user();

        $allSites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);

        $canAjuster = $this->droitService->canAjuster($user, $orgId);
        $sitesAutorisesRaw = $canAjuster
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $allSites)
            : collect();

        $canAugmenter = $this->droitService->canAugmenter($user, $orgId);
        $canDiminuer = $this->droitService->canDiminuer($user, $orgId);

        $varianteIds = $produit->variantes->pluck('id')->all();

        $stocksParSite = VarianteStock::whereIn('produit_variante_id', $varianteIds)
            ->with('site:id,nom,code')
            ->get()
            ->groupBy('site_id')
            ->map(function (Collection $parSite, $siteId) {
                $premier = $parSite->first();

                return [
                    'site_id' => $siteId,
                    'site_code' => $premier->site?->code,
                    'site_nom' => $premier->site?->nom,
                    'qte_stock' => $parSite->sum('qte_stock'),
                    'seuil_alerte_stock' => $parSite->first()?->seuil_alerte_stock,
                    'is_alerte' => $parSite->contains('is_alerte', true),
                    'updated_at' => $parSite->max('updated_at'),
                ];
            })
            ->values();

        $totalStock = $stocksParSite->isNotEmpty() ? $stocksParSite->sum('qte_stock') : (int) ($produit->qte_stock ?? 0);

        $mouvements = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->with(['createur:id,prenom,nom', 'site:id,nom,code', 'variante:id,combo_hash'])
            ->orderByDesc('created_at')
            ->take(100)
            ->get()
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'site_nom' => $m->site?->nom,
                'site_code' => $m->site?->code,
                'created_at' => $m->created_at?->toISOString(),
                'createur_nom' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'is_initial' => false,
            ])
            ->toArray();

        $creation = AuditLog::where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->where('event_code', 'CREATED')
            ->first();

        if ($creation && isset($creation->new_values['qte_stock']) && (float) $creation->new_values['qte_stock'] > 0) {
            $mouvements[] = [
                'id' => 'initial-'.$produit->id,
                'type' => 'entree',
                'quantite' => (int) $creation->new_values['qte_stock'],
                'stock_avant' => 0,
                'stock_apres' => (int) $creation->new_values['qte_stock'],
                'notes' => 'Stock initial — création du produit',
                'site_nom' => null,
                'site_code' => null,
                'created_at' => $creation->created_at?->toISOString(),
                'createur_nom' => $creation->actor_name_snapshot,
                'is_initial' => true,
            ];
        }

        $variantePrincipale = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $seuilDisplay = $variantePrincipale?->seuil_alerte_stock;
        $hasStock = $produit->type?->hasStock() ?? true;
        $isLowStock = $hasStock && $totalStock > 0 && $seuilDisplay !== null && $seuilDisplay > 0 && $totalStock <= $seuilDisplay;

        return Inertia::render('Produits/Show', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie' => $produit->categorie ? ['id' => $produit->categorie->id, 'nom' => $produit->categorie->nom] : null,
                'code_interne' => $variantePrincipale?->code_interne,
                'code_fournisseur' => $variantePrincipale?->code_fournisseur,
                'code_barres' => $variantePrincipale?->code_barres,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'type_label' => $produit->type?->label(),
                'statut' => $produit->statut?->value,
                'statut_label' => $produit->statut?->label(),
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'qte_stock' => $totalStock,
                'seuil_alerte_stock' => $seuilDisplay,
                'description' => $produit->description,
                'is_alerte' => $produit->is_alerte,
                'in_stock' => ! $hasStock || $totalStock > 0,
                'is_low_stock' => $isLowStock,
                'has_stock' => $hasStock,
                'created_at' => $produit->created_at?->toISOString(),
                'updated_at' => $produit->updated_at?->toISOString(),
                'stocks_par_site' => $stocksParSite,
                'medias' => $produit->medias->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => $m->url,
                    'thumb_url' => $m->thumb_url,
                    'is_primary' => $m->is_primary,
                    'position' => $m->position,
                ]),
                'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                    'id' => $v->id,
                    'libelle' => $v->libelle,
                    'code_interne' => $v->code_interne,
                    'code_barres' => $v->code_barres,
                    'prix_vente' => $v->prix_vente,
                    'is_default' => $v->is_default,
                    'is_active' => $v->is_active,
                ]),
            ],
            'mouvements' => collect($mouvements),
            'historiques' => $this->loadModifications($produit),
            'can_ajuster_stock' => $canAjuster,
            'can_augmenter_stock' => $canAugmenter,
            'can_diminuer_stock' => $canDiminuer,
            'sites_autorises' => $sitesAutorisesRaw->values(),
        ]);
    }

    public function historique(Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $varianteIds = $produit->variantes()->pluck('id');

        $ajustements = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->with(['createur:id,prenom,nom', 'site:id,nom,code'])
            ->orderByDesc('created_at')
            ->take(200)
            ->get()
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'site_nom' => $m->site?->nom,
                'site_code' => $m->site?->code,
                'createur_nom' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'created_at' => $m->created_at?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'ajustements' => $ajustements,
            'modifications' => $this->loadModifications($produit),
        ]);
    }

    public function edit(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        $produit->load(['variantes', 'medias']);
        $variantePrincipale = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $orgId = $produit->organization_id;

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie_id' => $produit->categorie_id,
                'code_interne' => $variantePrincipale?->code_interne,
                'code_barres' => $variantePrincipale?->code_barres,
                'code_fournisseur' => $variantePrincipale?->code_fournisseur,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'statut' => $produit->statut?->value,
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'seuil_alerte_stock' => $variantePrincipale?->seuil_alerte_stock,
                'description' => $produit->description,
                'is_alerte' => $produit->is_alerte,
                'has_variantes' => $produit->variantes->count() > 1,
                'medias' => $produit->medias->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => $m->url,
                    'thumb_url' => $m->thumb_url,
                    'is_primary' => $m->is_primary,
                    'position' => $m->position,
                ]),
            ],
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'limites' => $this->limitesCatalogue($orgId),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $this->validerFormulaire($request, $produit);

        $oldSnapshot = $this->produitSnapshot($produit->fresh(['variantes']));

        $produit = DB::transaction(function () use ($produit, $data, $request) {
            $produit = $this->produitService->mettreAJourSimple($produit, $data);

            if ($request->hasFile('images')) {
                $this->mediaService->ajouter($produit, $request->file('images'));
            }

            return $produit;
        });

        $newSnapshot = $this->produitSnapshot($produit->fresh(['variantes']));

        [$oldDiff, $newDiff] = $this->produitDiff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($produit, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('produits.index')->with('success', 'Produit mis à jour avec succès.');
    }

    public function archiver(Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::UPDATED,
            auth()->user(),
            ['statut' => $produit->statut?->value],
            ['statut' => ProduitStatut::ARCHIVE->value],
        );

        $produit->update(['statut' => ProduitStatut::ARCHIVE]);

        return redirect()->back()->with('success', "{$produit->nom} a été archivé.");
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        $this->authorize('delete', $produit);

        $this->auditService->record($produit, AuditEvent::DELETED, auth()->user(), $this->produitSnapshot($produit->fresh(['variantes'])), null);

        foreach ($produit->medias as $media) {
            $this->mediaService->supprimer($media);
        }

        $produit->delete();

        return redirect()->route('produits.index')->with('success', 'Produit supprimé.');
    }

    public function ajusterStock(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('ajusterStock', $produit);
        abort_unless((bool) $produit->type?->hasStock(), 422, 'Ce produit ne gère pas de stock.');

        $data = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'variante_id' => ['nullable', 'exists:produit_variantes,id'],
            'augmenter' => ['nullable', 'integer', 'min:1'],
            'diminuer' => ['nullable', 'integer', 'min:1'],
            'motif_type' => ['required', Rule::in(MotifAjustementStock::validValues())],
            'motif_detail' => ['required_if:motif_type,autre', 'nullable', 'string', 'max:500'],
        ], [
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Le site sélectionné est invalide.',
            'augmenter.integer' => 'La quantité doit être un nombre entier.',
            'augmenter.min' => 'La quantité doit être supérieure à 0.',
            'diminuer.integer' => 'La quantité doit être un nombre entier.',
            'diminuer.min' => 'La quantité doit être supérieure à 0.',
            'motif_type.required' => 'Le motif est obligatoire.',
            'motif_type.in' => 'Le motif sélectionné est invalide.',
            'motif_detail.required_if' => 'Veuillez préciser le motif.',
            'motif_detail.max' => 'Le détail du motif ne peut pas dépasser 500 caractères.',
        ]);

        $site = Site::where('id', $data['site_id'])->where('organization_id', $produit->organization_id)->firstOrFail();

        $variante = $data['variante_id'] ?? null
            ? ProduitVariante::where('id', $data['variante_id'])->where('produit_id', $produit->id)->firstOrFail()
            : ($produit->variantePrincipale()->first() ?? abort(422, 'Ce produit n\'a aucune variante.'));

        $user = auth()->user();
        $hasAugmenter = ! empty($data['augmenter']);
        $hasDiminuer = ! empty($data['diminuer']);

        $direction = $hasAugmenter ? 'augmenter' : 'diminuer';
        if (! $this->droitService->canAjusterSurSite($user, $produit->organization_id, $site->id, $direction)) {
            abort(403, 'Vous n\'êtes pas autorisé à '.$direction.' le stock de cette agence.');
        }

        if ($hasAugmenter && $hasDiminuer) {
            throw ValidationException::withMessages(['augmenter' => 'Renseignez uniquement l\'un des deux champs.']);
        }
        if (! $hasAugmenter && ! $hasDiminuer) {
            throw ValidationException::withMessages(['augmenter' => 'Veuillez renseigner la quantité à augmenter ou à diminuer.']);
        }

        $direction = $hasAugmenter ? 'entree' : 'sortie';
        if (! in_array($data['motif_type'], MotifAjustementStock::validValuesForDirection($direction), true)) {
            throw ValidationException::withMessages(['motif_type' => 'Ce motif n\'est pas valide pour ce type d\'ajustement.']);
        }

        $existingCount = VarianteStock::where('produit_variante_id', $variante->id)->count();
        $varianteStock = VarianteStock::firstOrCreate(
            ['produit_variante_id' => $variante->id, 'site_id' => $site->id],
            [
                'organization_id' => $produit->organization_id,
                'qte_stock' => $existingCount === 0 ? (int) ($produit->qte_stock ?? 0) : 0,
            ]
        );

        $stockAvant = $varianteStock->qte_stock;
        $notes = MotifAjustementStock::from($data['motif_type'])->toNotesString($data['motif_detail'] ?? '');

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible sur ce site ({$stockAvant}).",
            ]);
        }

        $quantite = $hasAugmenter ? (int) $data['augmenter'] : (int) $data['diminuer'];
        $type = $hasAugmenter ? 'entree' : 'sortie';
        $stockApres = $hasAugmenter ? $stockAvant + $quantite : $stockAvant - $quantite;

        DB::transaction(function () use ($produit, $varianteStock, $variante, $site, $type, $quantite, $stockAvant, $stockApres, $notes, $user) {
            $varianteStock->update(['qte_stock' => $stockApres]);
            $produit->resynchroniserQteStock();

            MouvementStock::create([
                'organization_id' => $produit->organization_id,
                'site_id' => $site->id,
                'produit_variante_id' => $variante->id,
                'type' => $type,
                'quantite' => $quantite,
                'stock_avant' => $stockAvant,
                'stock_apres' => $stockApres,
                'notes' => $notes,
                'created_by' => $user->id,
            ]);

            $this->auditService->record(
                $produit,
                AuditEvent::STOCK_ADJUSTED,
                $user,
                ['qte_stock' => $stockAvant, 'site' => $site->nom],
                [
                    'qte_stock' => $stockApres,
                    'site' => $site->nom,
                    'motif' => $notes,
                    'role' => $user->roles->first()?->name,
                ],
            );
        });

        return back()->with('success', 'Stock mis à jour avec succès.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function limitesCatalogue(string $orgId): array
    {
        return [
            'max_photos_produit' => Parametre::getMaxPhotosProduit($orgId),
            'max_options_produit' => Parametre::getMaxOptionsProduit($orgId),
            'max_valeurs_option' => Parametre::getMaxValeursOption($orgId),
            'max_variantes_produit' => Parametre::getMaxVariantesProduit($orgId),
        ];
    }

    /**
     * Validation partagée create/update. Le prix requis selon le type est vérifié par
     * ProduitService::validerPrixSelonType() (appelé par le service, pas ici) pour rester
     * l'unique point de vérité partagé avec l'API.
     */
    private function validerFormulaire(Request $request, ?Produit $produit = null): array
    {
        $orgId = auth()->user()->organization_id;

        return $request->validate([
            'nom' => 'required|string|max:255',
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'code_barres' => 'nullable|string|max:100',
            'code_fournisseur' => 'nullable|string|max:100',
            'type' => 'required|in:'.implode(',', ProduitType::values()),
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_alerte' => 'boolean',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
            // Optionnel : déclinaisons (couleur/taille...). Absent/vide = produit simple
            // (variante par défaut invisible). Consommé par ProduitService::creer().
            'options' => 'nullable|array',
            'options.*.nom' => 'required_with:options|string|max:100',
            'options.*.valeurs' => 'required_with:options|array|min:1',
            'options.*.valeurs.*' => 'required|string|max:100',
        ]);
    }

    private function loadModifications(Produit $produit): array
    {
        return AuditLog::where('organization_id', $produit->organization_id)
            ->where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->where('event_code', '!=', AuditEvent::STOCK_ADJUSTED->value)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ])
            ->all();
    }

    private function produitSnapshot(Produit $produit): array
    {
        $variante = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();

        return array_filter([
            'nom' => $produit->nom,
            'type' => $produit->type?->label(),
            'statut' => $produit->statut?->label(),
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'prix_usine' => $variante?->prix_usine,
            'cout' => $variante?->cout,
            'qte_stock' => $produit->qte_stock,
            'seuil_alerte_stock' => $variante?->seuil_alerte_stock,
            'is_alerte' => $produit->is_alerte,
            'description' => $produit->description,
            'code_fournisseur' => $variante?->code_fournisseur,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function produitDiff(array $before, array $after): array
    {
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $oldDiff = [];
        $newDiff = [];

        $normalize = fn ($v) => is_numeric($v) ? rtrim(number_format((float) $v, 2, '.', ''), '0') : (string) ($v ?? '');

        foreach ($allKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;
            if ($normalize($oldVal) !== $normalize($newVal)) {
                $oldDiff[$key] = $oldVal;
                $newDiff[$key] = $newVal;
            }
        }

        return [empty($oldDiff) ? null : $oldDiff, empty($newDiff) ? null : $newDiff];
    }
}
