<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementStock;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\AuditLog;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ProduitStock;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\DroitAjustementStockService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly DroitAjustementStockService $droitService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Produit::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'site_id']);

        $query = Produit::where('organization_id', $orgId)->orderBy('nom');

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('nom', 'like', "%{$s}%")
                ->orWhere('code_interne', 'like', "%{$s}%"));
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        $produits = $query->get();
        $produitIds = $produits->pluck('id')->all();

        // Charger tous les stocks par site en une requête
        $allProduitStocks = ProduitStock::whereIn('produit_id', $produitIds)
            ->with('site:id,nom,code')
            ->get()
            ->groupBy('produit_id');

        // Produits utilisés dans des commandes
        $usedIds = collect()
            ->merge(DB::table('commande_vente_lignes')->whereIn('produit_id', $produitIds)->pluck('produit_id'))
            ->merge(DB::table('commande_achat_lignes')->whereIn('produit_id', $produitIds)->whereNotNull('produit_id')->pluck('produit_id'))
            ->unique()->flip()->all();

        $filteredSiteId = $filters['site_id'] ?? null;

        // Dernier mouvement par produit (filtré par site si filtre actif)
        $lastMouvements = MouvementStock::whereIn('produit_id', $produitIds)
            ->when($filteredSiteId, fn ($q) => $q->where('site_id', $filteredSiteId))
            ->orderByDesc('created_at')
            ->get(['produit_id', 'type', 'quantite'])
            ->groupBy('produit_id')
            ->map(fn ($ms) => $ms->first());

        $mapped = $produits->map(function (Produit $p) use ($allProduitStocks, $filteredSiteId, $usedIds, $lastMouvements) {
            $siteStocks = $allProduitStocks->get($p->id, collect());
            $hasStock = $p->type?->hasStock() ?? true;

            if ($filteredSiteId) {
                $siteStock = $siteStocks->firstWhere('site_id', $filteredSiteId);
                $qteDisplay = $siteStock?->qte_stock ?? 0;
                $seuilDisplay = $siteStock?->seuil_alerte_stock ?? $p->seuil_alerte_stock;
            } else {
                $qteDisplay = $siteStocks->isNotEmpty()
                    ? $siteStocks->sum('qte_stock')
                    : (int) ($p->qte_stock ?? 0);
                $seuilDisplay = $p->seuil_alerte_stock;
            }

            $inStock = ! $hasStock || $qteDisplay > 0;
            $isLowStock = $hasStock && $qteDisplay > 0 && $seuilDisplay !== null && $seuilDisplay > 0 && $qteDisplay <= $seuilDisplay;
            $lastMouvement = $lastMouvements->get($p->id);

            return [
                'id' => $p->id,
                'nom' => $p->nom,
                'code_interne' => $p->code_interne,
                'code_fournisseur' => $p->code_fournisseur,
                'type' => $p->type?->value,
                'type_label' => $p->type?->label(),
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut?->label(),
                'image_url' => $p->image_url,
                'prix_usine' => $p->prix_usine,
                'prix_vente' => $p->prix_vente,
                'prix_achat' => $p->prix_achat,
                'cout' => $p->cout,
                'description' => $p->description,
                'is_alerte' => $p->is_alerte,
                'qte_stock' => $qteDisplay,
                'seuil_alerte_stock' => $seuilDisplay,
                'has_stock' => $hasStock,
                'in_stock' => $inStock,
                'is_low_stock' => $isLowStock,
                'is_used' => isset($usedIds[$p->id]),
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

        $allSites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code']);

        $user = auth()->user();
        $canAjuster = $this->droitService->canAjuster($user, $orgId);
        $sitesAutorisesRaw = $canAjuster
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $allSites)
            : collect();

        return Inertia::render('Produits/Index', [
            'produits' => $mapped,
            'sites' => $allSites,
            'can_ajuster_stock' => $canAjuster,
            'can_augmenter_stock' => $this->droitService->canAugmenter($user, $orgId),
            'can_diminuer_stock' => $this->droitService->canDiminuer($user, $orgId),
            'sites_autorises' => $sitesAutorisesRaw->values(),
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Produit::class);

        return Inertia::render('Produits/Create', [
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Produit::class);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code_fournisseur' => 'nullable|string|max:100',
            'type' => 'required|in:'.implode(',', ProduitType::values()),
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'qte_stock' => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_alerte' => 'boolean',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = (new ImageService)->storeAsWebp($request->file('image'), 'produits');
            $data['image_url'] = '/storage/'.$path;
        }
        unset($data['image']);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $produit = Produit::create([...$data, 'organization_id' => $orgId]);

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            auth()->user(),
            null,
            $this->produitSnapshot($produit),
        );

        return redirect()->route('produits.index')
            ->with('success', 'Produit créé avec succès.');
    }

    public function show(Produit $produit): Response
    {
        $this->authorize('view', $produit);

        $orgId = $produit->organization_id;
        $user = auth()->user();

        $allSites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code']);

        $canAjuster = $this->droitService->canAjuster($user, $orgId);
        $sitesAutorisesRaw = $canAjuster
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $allSites)
            : collect();

        $canAugmenter = $this->droitService->canAugmenter($user, $orgId);
        $canDiminuer = $this->droitService->canDiminuer($user, $orgId);

        $stocksParSite = ProduitStock::where('produit_id', $produit->id)
            ->with('site:id,nom,code')
            ->get()
            ->map(fn ($s) => [
                'site_id' => $s->site_id,
                'site_code' => $s->site?->code,
                'site_nom' => $s->site?->nom,
                'qte_stock' => $s->qte_stock,
                'seuil_alerte_stock' => $s->seuil_alerte_stock,
                'is_alerte' => $s->is_alerte,
                'updated_at' => $s->updated_at?->toISOString(),
            ])
            ->values();

        $totalStock = $stocksParSite->isNotEmpty()
            ? $stocksParSite->sum('qte_stock')
            : (int) ($produit->qte_stock ?? 0);

        $mouvements = MouvementStock::where('produit_id', $produit->id)
            ->with(['createur:id,prenom,nom', 'site:id,nom,code'])
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

        // Stock initial dérivé de l'audit de création
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

        $seuilDisplay = $produit->seuil_alerte_stock;
        $hasStock = $produit->type?->hasStock() ?? true;
        $isLowStock = $hasStock && $totalStock > 0 && $seuilDisplay !== null && $seuilDisplay > 0 && $totalStock <= $seuilDisplay;

        return Inertia::render('Produits/Show', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'code_interne' => $produit->code_interne,
                'code_fournisseur' => $produit->code_fournisseur,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'type_label' => $produit->type?->label(),
                'statut' => $produit->statut?->value,
                'statut_label' => $produit->statut?->label(),
                'prix_usine' => $produit->prix_usine,
                'prix_vente' => $produit->prix_vente,
                'prix_achat' => $produit->prix_achat,
                'cout' => $produit->cout,
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

        $ajustements = MouvementStock::where('produit_id', $produit->id)
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

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'code_interne' => $produit->code_interne,
                'code_fournisseur' => $produit->code_fournisseur,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'statut' => $produit->statut?->value,
                'prix_usine' => $produit->prix_usine,
                'prix_vente' => $produit->prix_vente,
                'prix_achat' => $produit->prix_achat,
                'cout' => $produit->cout,
                'qte_stock' => $produit->qte_stock,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'description' => $produit->description,
                'is_alerte' => $produit->is_alerte,
            ],
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code_fournisseur' => 'nullable|string|max:100',
            'type' => 'required|in:'.implode(',', ProduitType::values()),
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'qte_stock' => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_alerte' => 'boolean',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $imageService = new ImageService;
            $imageService->delete(str_replace('/storage/', '', $produit->image_url ?? ''));
            $path = $imageService->storeAsWebp($request->file('image'), 'produits');
            $data['image_url'] = '/storage/'.$path;
        }
        unset($data['image']);

        $oldSnapshot = $this->produitSnapshot($produit);
        $produit->update($data);
        $produit->refresh();
        $newSnapshot = $this->produitSnapshot($produit);

        [$oldDiff, $newDiff] = $this->produitDiff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($produit, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('produits.index')
            ->with('success', 'Produit mis à jour avec succès.');
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

        $this->auditService->record($produit, AuditEvent::DELETED, auth()->user(), $this->produitSnapshot($produit), null);

        if ($produit->image_url) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $produit->image_url));
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

        // Vérifier que le site appartient à l'organisation du produit
        $site = Site::where('id', $data['site_id'])
            ->where('organization_id', $produit->organization_id)
            ->firstOrFail();

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

        // Récupérer ou initialiser le stock pour ce site
        $existingCount = ProduitStock::where('produit_id', $produit->id)->count();
        $produitStock = ProduitStock::firstOrCreate(
            ['produit_id' => $produit->id, 'site_id' => $site->id],
            [
                'organization_id' => $produit->organization_id,
                // Premier enregistrement : migrer le stock global existant
                'qte_stock' => $existingCount === 0 ? (int) ($produit->qte_stock ?? 0) : 0,
            ]
        );

        $stockAvant = $produitStock->qte_stock;
        $notes = MotifAjustementStock::from($data['motif_type'])->toNotesString($data['motif_detail'] ?? '');

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible sur ce site ({$stockAvant}).",
            ]);
        }

        $quantite = $hasAugmenter ? (int) $data['augmenter'] : (int) $data['diminuer'];
        $type = $hasAugmenter ? 'entree' : 'sortie';
        $stockApres = $hasAugmenter ? $stockAvant + $quantite : $stockAvant - $quantite;

        DB::transaction(function () use ($produit, $produitStock, $site, $type, $quantite, $stockAvant, $stockApres, $notes, $user) {
            $produitStock->update(['qte_stock' => $stockApres]);

            // Mettre à jour le stock agrégé du produit
            $totalStock = ProduitStock::where('produit_id', $produit->id)->sum('qte_stock');
            $produit->update(['qte_stock' => $totalStock]);

            MouvementStock::create([
                'organization_id' => $produit->organization_id,
                'site_id' => $site->id,
                'produit_id' => $produit->id,
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
        return array_filter([
            'nom' => $produit->nom,
            'type' => $produit->type?->label(),
            'statut' => $produit->statut?->label(),
            'prix_vente' => $produit->prix_vente,
            'prix_achat' => $produit->prix_achat,
            'prix_usine' => $produit->prix_usine,
            'cout' => $produit->cout,
            'qte_stock' => $produit->qte_stock,
            'seuil_alerte_stock' => $produit->seuil_alerte_stock,
            'is_alerte' => $produit->is_alerte,
            'description' => $produit->description,
            'code_fournisseur' => $produit->code_fournisseur,
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
