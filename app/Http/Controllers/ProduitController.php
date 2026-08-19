<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementStock;
use App\Enums\ProduitStatut;
use App\Enums\StockStatut;
use App\Models\AuditLog;
use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\OptionCatalogue;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\AuditLogService;
use App\Services\DroitAjustementStockService;
use App\Services\MediaService;
use App\Services\MouvementStockService;
use App\Services\ProduitService;
use App\Services\StockStatutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        private readonly StockStatutService $stockStatutService,
    ) {}

    /**
     * Types actifs de l'organisation, pour peupler les sélecteurs Create/Edit/filtre — remplace
     * l'ancien App\Enums\ProduitType::options() figé, désormais un vrai CRUD par organisation
     * (cf. ProduitTypeController).
     */
    private function typesOptions(string $orgId): Collection
    {
        return ProduitType::where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->orderBy('position')->orderBy('nom')
            ->get(['id', 'nom', 'gere_stock', 'vendable', 'achetable', 'prix_achat_requis', 'prix_usine_requis', 'prix_vente_requis'])
            ->map(fn (ProduitType $t) => [
                'value' => $t->id,
                'label' => $t->nom,
                'gere_stock' => $t->gere_stock,
                'required_prices' => $t->requiredPrices(),
                // Applicabilité fonctionnelle (déjà utilisée pour filtrer les flux
                // achat/vente, cf. CommandeAchatController/CommandeVenteController/
                // PdvController) — réutilisée ici pour piloter la visibilité des champs
                // prix_achat/prix_vente dans le formulaire, distincte de l'obligation de
                // saisie (*_requis, cf. required_prices ci-dessus).
                'achetable' => $t->achetable,
                'vendable' => $t->vendable,
            ]);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Produit::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $filters = $request->only(['search', 'produit_type_id', 'statut', 'categorie_id']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        if (empty($siteIds) && ! $isAdmin) {
            $siteIds = $user->sites()->pluck('sites.id')->map(fn ($id) => (string) $id)->toArray();
        }

        $query = Produit::where('organization_id', $orgId)
            ->with([
                'categorie:id,nom',
                'produitType',
                'variantes' => fn ($q) => $q->orderBy('position'),
                // Pas de limit() ici : une contrainte LIMIT sur un eager load hasMany s'applique
                // à l'ensemble du résultat, pas par produit. getImageUrlAttribute() prend le
                // premier (is_primary puis position) dans la collection déjà chargée.
                'medias' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
            ])
            ->orderBy('nom');

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            // Recherche par nom, référence (sku) ou code-barres — mêmes deux notions
            // d'identifiant externe que côté PDV (cf. PdvController::produitsPdv()).
            $query->where(fn ($q) => $q->where('nom', 'like', "%{$s}%")
                ->orWhereHas('variantes', fn ($vq) => $vq->where('sku', 'like', "%{$s}%")
                    ->orWhere('code_barres', 'like', "%{$s}%")));
        }
        if (! empty($filters['produit_type_id'])) {
            $query->where('produit_type_id', $filters['produit_type_id']);
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

        // commande_vente_lignes/commande_achat_lignes portent variante_id (grain Phase 2) :
        // on résout produit_id via la map variante → produit déjà construite ci-dessus.
        $varianteToProduitId = $produits->flatMap(
            fn (Produit $p) => $p->variantes->pluck('id')->mapWithKeys(fn ($vid) => [$vid => $p->id])
        );
        $usedVarianteIds = collect()
            ->merge(DB::table('commande_vente_lignes')->whereIn('variante_id', $varianteIds)->pluck('variante_id'))
            ->merge(DB::table('commande_achat_lignes')->whereIn('variante_id', $varianteIds)->whereNotNull('variante_id')->pluck('variante_id'))
            ->unique();
        $usedProduitIds = $usedVarianteIds->map(fn ($vid) => $varianteToProduitId->get($vid))->filter()->unique()->flip()->all();

        $lastMouvementsParVariante = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->when(! empty($siteIds), fn ($q) => $q->whereIn('site_id', $siteIds))
            ->orderByDesc('created_at')
            ->get(['produit_variante_id', 'type', 'quantite', 'created_at'])
            ->groupBy('produit_variante_id')
            ->map(fn ($ms) => $ms->first());

        $mapped = $produits->map(function (Produit $p) use ($allVarianteStocks, $siteIds, $usedProduitIds, $lastMouvementsParVariante) {
            $varianteIdsProduit = $p->variantes->pluck('id')->all();
            $siteStocksAll = collect($varianteIdsProduit)->flatMap(fn ($vid) => $allVarianteStocks->get($vid, collect()));
            $variantePrincipale = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
            $hasStock = $p->produitType?->gere_stock ?? true;

            $siteStocksScope = empty($siteIds)
                ? $siteStocksAll
                : $siteStocksAll->filter(fn ($s) => in_array((string) $s->site_id, $siteIds, true));

            // Sans filtre agence : agrégat réel si des lignes VarianteStock existent déjà,
            // sinon repli sur l'agrégat legacy Produit::qte_stock (aucune meilleure source
            // avant la première ventilation par site). Avec un filtre agence explicite : la
            // somme scopée fait foi, 0 si l'agence n'a encore aucune ligne — JAMAIS de repli
            // sur l'agrégat global, qui mélangerait le stock d'agences non sélectionnées
            // (cf. régression multi-agences : filtrer une agence ne doit jamais afficher le
            // total de toutes les agences).
            $qteDisplay = empty($siteIds)
                ? ($siteStocksAll->isNotEmpty() ? (int) $siteStocksAll->sum('qte_stock') : (int) ($p->qte_stock ?? 0))
                : (int) $siteStocksScope->sum('qte_stock');

            // Seuil désormais unique au niveau PRODUIT (repli sur le seuil global de
            // l'organisation) — appliqué à chaque ligne variante × site individuellement,
            // jamais à un total agrégé (cf. décision produit : un stock élevé ailleurs ne doit
            // jamais masquer une alerte locale).
            $seuilEffectif = $this->stockStatutService->seuilEffectif($p);
            $alerteActive = $hasStock && (bool) $p->alerte_stock_active;

            if ($hasStock && $siteStocksScope->isNotEmpty()) {
                $isRupture = $siteStocksScope->contains(fn ($s) => $s->qte_stock <= 0);
                $isLowStock = $siteStocksScope->contains(
                    fn ($s) => $this->stockStatutService->statutPour($s->qte_stock, $seuilEffectif, $alerteActive) === StockStatut::STOCK_FAIBLE
                );
            } elseif ($hasStock) {
                $statutFallback = $this->stockStatutService->statutPour($qteDisplay, $seuilEffectif, $alerteActive);
                $isRupture = $statutFallback === StockStatut::RUPTURE;
                $isLowStock = $statutFallback === StockStatut::STOCK_FAIBLE;
            } else {
                $isRupture = false;
                $isLowStock = false;
            }
            $inStock = ! $hasStock || ! $isRupture;

            $lastMouvement = collect($varianteIdsProduit)
                ->map(fn ($vid) => $lastMouvementsParVariante->get($vid))
                ->filter()
                ->sortByDesc('created_at')
                ->first();

            $isUsed = isset($usedProduitIds[$p->id]);

            return [
                'id' => $p->id,
                'nom' => $p->nom,
                'categorie_id' => $p->categorie_id,
                'categorie_nom' => $p->categorie?->nom,
                'sku' => $variantePrincipale?->sku,
                'code_barres' => $variantePrincipale?->code_barres,
                'produit_type_id' => $p->produit_type_id,
                'type_nom' => $p->produitType?->nom,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut?->label(),
                'image_url' => $p->image_url,
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_usine_tricycle' => $variantePrincipale?->prix_usine_tricycle,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'description' => $p->description,
                'alerte_stock_active' => $p->alerte_stock_active,
                'qte_stock' => $qteDisplay,
                'seuil_alerte_stock' => $p->seuil_alerte_stock,
                'seuil_alerte_effectif' => $seuilEffectif,
                'has_stock' => $hasStock,
                'in_stock' => $inStock,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isRupture,
                'is_used' => $isUsed,
                'variantes_count' => $p->variantes->count(),
                'has_variantes' => $p->variantes->count() > 1,
                'last_mouvement_type' => $lastMouvement?->type,
                'last_mouvement_quantite' => $lastMouvement?->quantite,
                'stocks_par_site' => $siteStocksAll->map(fn ($s) => [
                    'site_id' => $s->site_id,
                    'site_code' => $s->site?->code,
                    'site_nom' => $s->site?->nom,
                    'qte_stock' => $s->qte_stock,
                    'statut' => ($hasStock ? $this->stockStatutService->statutPour($s->qte_stock, $seuilEffectif, $alerteActive) : StockStatut::DISPONIBLE)->value,
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
            'types' => $this->typesOptions($orgId),
            'statuts' => ProduitStatut::options(),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Produit::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Produits/Create', [
            'types' => $this->typesOptions($orgId),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'optionsCatalogue' => OptionCatalogue::where('organization_id', $orgId)
                ->orderBy('position')->orderBy('nom')
                ->with('valeurs:id,option_catalogue_id,valeur,hex')
                ->get(['id', 'nom']),
            'fournisseurs' => $this->fournisseursOptions($orgId),
            'limites' => $this->limitesCatalogue($orgId),
            'seuilOrganisationDefaut' => Parametre::getSeuilStockFaible($orgId),
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
            $this->produitSnapshot($produit->fresh(['variantes', 'fournisseur', 'produitType'])),
        );

        return redirect()->route('produits.show', $produit)->with('success', 'Produit créé avec succès.');
    }

    public function show(Produit $produit): Response
    {
        $this->authorize('view', $produit);

        $produit->load(['categorie', 'fournisseur', 'produitType', 'variantes.valeurs.option', 'variantes.media', 'medias']);
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

        $varianteStocksRaw = VarianteStock::whereIn('produit_variante_id', $varianteIds)
            ->with('site:id,nom,code')
            ->get();

        // Grain variante+site — consommé par AjusterStockModal.vue pour afficher/pré-remplir
        // le stock réel de la variante sélectionnée (stocksParSite ci-dessous est un agrégat
        // toutes variantes confondues, insuffisant dès qu'on ajuste une variante précise).
        $varianteStocksParSiteEtVariante = $varianteStocksRaw
            ->map(fn (VarianteStock $s) => [
                'variante_id' => $s->produit_variante_id,
                'site_id' => $s->site_id,
                'qte_stock' => $s->qte_stock,
            ])
            ->values();

        $totalStock = $varianteStocksRaw->isNotEmpty() ? $varianteStocksRaw->sum('qte_stock') : (int) ($produit->qte_stock ?? 0);

        // Seuil unique au niveau PRODUIT, évalué pour CHAQUE couple variante × site
        // individuellement — jamais sur le total agrégé (cf. décision produit : un stock élevé
        // ailleurs — autre variante, autre site — ne doit jamais masquer une alerte locale).
        $hasStock = $produit->produitType?->gere_stock ?? true;
        $seuilEffectif = $this->stockStatutService->seuilEffectif($produit);
        $alerteActive = $hasStock && (bool) $produit->alerte_stock_active;
        $varianteLibelleParId = $produit->variantes->pluck('libelle', 'id');

        $varianteStocksDetail = $varianteStocksRaw->map(function (VarianteStock $s) use ($hasStock, $seuilEffectif, $alerteActive, $varianteLibelleParId) {
            $statut = $hasStock ? $this->stockStatutService->statutPour($s->qte_stock, $seuilEffectif, $alerteActive) : StockStatut::DISPONIBLE;

            return [
                'variante_id' => $s->produit_variante_id,
                'variante_libelle' => $varianteLibelleParId->get($s->produit_variante_id, ''),
                'site_id' => $s->site_id,
                'site_code' => $s->site?->code,
                'site_nom' => $s->site?->nom,
                'qte_stock' => $s->qte_stock,
                'statut' => $statut->value,
                'statut_label' => $statut->label(),
            ];
        })->values();

        $stocksParSite = $varianteStocksRaw
            ->groupBy('site_id')
            ->map(function (Collection $parSite, $siteId) use ($hasStock, $seuilEffectif, $alerteActive) {
                $premier = $parSite->first();
                // Pire statut parmi les variantes de ce site (rupture > stock faible >
                // disponible) — un résumé par site reste utile, mais ne doit jamais faire
                // disparaître le pire cas derrière une moyenne/somme.
                $statuts = $hasStock
                    ? $parSite->map(fn (VarianteStock $s) => $this->stockStatutService->statutPour($s->qte_stock, $seuilEffectif, $alerteActive))
                    : collect();
                $pire = match (true) {
                    $statuts->contains(StockStatut::RUPTURE) => StockStatut::RUPTURE,
                    $statuts->contains(StockStatut::STOCK_FAIBLE) => StockStatut::STOCK_FAIBLE,
                    default => StockStatut::DISPONIBLE,
                };

                return [
                    'site_id' => $siteId,
                    'site_code' => $premier->site?->code,
                    'site_nom' => $premier->site?->nom,
                    'qte_stock' => $parSite->sum('qte_stock'),
                    'statut' => $pire->value,
                    'statut_label' => $pire->label(),
                    'updated_at' => $parSite->max('updated_at'),
                ];
            })
            ->values();

        $mouvements = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->with(['createur:id,personne_id', 'createur.personne', 'site:id,nom,code', 'variante:id,combo_hash'])
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

        if ($hasStock && $varianteStocksDetail->isNotEmpty()) {
            $isRupture = $varianteStocksDetail->contains(fn (array $d) => $d['statut'] === StockStatut::RUPTURE->value);
            $isLowStock = $varianteStocksDetail->contains(fn (array $d) => $d['statut'] === StockStatut::STOCK_FAIBLE->value);
            $nombreAlertesStock = $varianteStocksDetail->filter(fn (array $d) => $d['statut'] !== StockStatut::DISPONIBLE->value)->count();
        } elseif ($hasStock) {
            $statutFallback = $this->stockStatutService->statutPour($totalStock, $seuilEffectif, $alerteActive);
            $isRupture = $statutFallback === StockStatut::RUPTURE;
            $isLowStock = $statutFallback === StockStatut::STOCK_FAIBLE;
            $nombreAlertesStock = ($isRupture || $isLowStock) ? 1 : 0;
        } else {
            $isRupture = false;
            $isLowStock = false;
            $nombreAlertesStock = 0;
        }

        return Inertia::render('Produits/Show', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie' => $produit->categorie ? ['id' => $produit->categorie->id, 'nom' => $produit->categorie->nom] : null,
                'fournisseur' => $produit->fournisseur ? [
                    'id' => $produit->fournisseur->id,
                    'nom_complet' => $produit->fournisseur->nom_complet,
                    'phone' => $produit->fournisseur->phone,
                ] : null,
                'sku' => $variantePrincipale?->sku,
                'code_barres' => $variantePrincipale?->code_barres,
                'image_url' => $produit->image_url,
                'produit_type_id' => $produit->produit_type_id,
                'type_nom' => $produit->produitType?->nom,
                'prix_usine_requis' => (bool) $produit->produitType?->prix_usine_requis,
                // cf. typesOptions()/variantesIndex() : achetable/vendable pilotent la
                // visibilité de prix_achat/prix_vente dans VarianteEditModal.vue.
                'achetable' => (bool) ($produit->produitType?->achetable ?? true),
                'vendable' => (bool) ($produit->produitType?->vendable ?? true),
                'statut' => $produit->statut?->value,
                'statut_label' => $produit->statut?->label(),
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_usine_tricycle' => $variantePrincipale?->prix_usine_tricycle,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'qte_stock' => $totalStock,
                'alerte_stock_active' => $produit->alerte_stock_active,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'seuil_alerte_effectif' => $seuilEffectif,
                'description' => $produit->description,
                'in_stock' => ! $hasStock || ! $isRupture,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isRupture,
                'nombre_alertes_stock' => $nombreAlertesStock,
                'has_stock' => $hasStock,
                'created_at' => $produit->created_at?->toISOString(),
                'updated_at' => $produit->updated_at?->toISOString(),
                'stocks_par_site' => $stocksParSite,
                'variante_stocks_detail' => $varianteStocksDetail,
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
                    'sku' => $v->sku,
                    'code_barres' => $v->code_barres,
                    'prix_usine' => $v->prix_usine,
                    'prix_usine_tricycle' => $v->prix_usine_tricycle,
                    'prix_vente' => $v->prix_vente,
                    'prix_achat' => $v->prix_achat,
                    'cout' => $v->cout,
                    'is_default' => $v->is_default,
                    'is_active' => $v->is_active,
                    'options' => $this->varianteOptions($v),
                    'media_id' => $v->media_id,
                    'image_url' => $v->effective_image_url,
                ]),
            ],
            'mouvements' => collect($mouvements),
            'historiques' => $this->loadModifications($produit),
            'can_ajuster_stock' => $canAjuster,
            'can_augmenter_stock' => $canAugmenter,
            'can_diminuer_stock' => $canDiminuer,
            'sites_autorises' => $sitesAutorisesRaw->values(),
            'variante_stocks' => $varianteStocksParSiteEtVariante,
            'limites' => $this->limitesCatalogue($orgId),
        ]);
    }

    public function historique(Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $varianteIds = $produit->variantes()->pluck('id');

        $ajustements = MouvementStock::whereIn('produit_variante_id', $varianteIds)
            ->with(['createur:id,personne_id', 'createur.personne', 'site:id,nom,code'])
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

        $produit->load(['variantes.valeurs.option', 'medias']);
        $variantePrincipale = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $orgId = $produit->organization_id;

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie_id' => $produit->categorie_id,
                'fournisseur_id' => $produit->fournisseur_id,
                'sku' => $variantePrincipale?->sku,
                'code_barres' => $variantePrincipale?->code_barres,
                'image_url' => $produit->image_url,
                'produit_type_id' => $produit->produit_type_id,
                'statut' => $produit->statut?->value,
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_usine_tricycle' => $variantePrincipale?->prix_usine_tricycle,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'alerte_stock_active' => $produit->alerte_stock_active,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'description' => $produit->description,
                'has_variantes' => $produit->variantes->count() > 1,
                'variantes_count' => $produit->variantes->count(),
                'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                    'id' => $v->id,
                    'libelle' => $v->libelle,
                    'sku' => $v->sku,
                    'code_barres' => $v->code_barres,
                    'prix_usine' => $v->prix_usine,
                    'prix_usine_tricycle' => $v->prix_usine_tricycle,
                    'prix_vente' => $v->prix_vente,
                    'prix_achat' => $v->prix_achat,
                    'cout' => $v->cout,
                    'is_default' => $v->is_default,
                    'is_active' => $v->is_active,
                    'options' => $this->varianteOptions($v),
                ]),
                'medias' => $produit->medias->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => $m->url,
                    'thumb_url' => $m->thumb_url,
                    'is_primary' => $m->is_primary,
                    'position' => $m->position,
                ]),
            ],
            'types' => $this->typesOptions($orgId),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'fournisseurs' => $this->fournisseursOptions($orgId),
            'limites' => $this->limitesCatalogue($orgId),
            'seuilOrganisationDefaut' => Parametre::getSeuilStockFaible($orgId),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $this->validerFormulaire($request, $produit);

        $oldSnapshot = $this->produitSnapshot($produit->fresh(['variantes', 'fournisseur', 'produitType']));

        $produit = DB::transaction(function () use ($produit, $data, $request) {
            $produit = $this->produitService->mettreAJourSimple($produit, $data);

            if ($request->hasFile('images')) {
                $this->mediaService->ajouter($produit, $request->file('images'));
            }

            return $produit;
        });

        $newSnapshot = $this->produitSnapshot($produit->fresh(['variantes', 'fournisseur', 'produitType']));

        [$oldDiff, $newDiff] = $this->produitDiff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($produit, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('produits.show', $produit)->with('success', 'Produit mis à jour avec succès.');
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

        $this->auditService->record($produit, AuditEvent::DELETED, auth()->user(), $this->produitSnapshot($produit->fresh(['variantes', 'fournisseur', 'produitType'])), null);

        foreach ($produit->medias as $media) {
            $this->mediaService->supprimer($media);
        }

        $produit->delete();

        return redirect()->route('produits.index')->with('success', 'Produit supprimé.');
    }

    public function ajusterStock(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('ajusterStock', $produit);
        abort_unless((bool) $produit->produitType?->gere_stock, 422, 'Ce produit ne gère pas de stock.');

        $data = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'variante_id' => ['nullable', 'exists:produit_variantes,id'],
            'augmenter' => ['nullable', 'integer', 'min:1'],
            'diminuer' => ['nullable', 'integer', 'min:1'],
            'motif_type' => ['required', Rule::in(MotifAjustementStock::validValues())],
            'motif_detail' => [
                'required_if:motif_type,autre',
                'nullable',
                'string',
                'max:500',
                function ($attribute, $value, $fail) {
                    if ($value !== null && trim($value) === '') {
                        $fail('Veuillez préciser le motif.');
                    }
                },
            ],
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

        // Produit à vraies déclinaisons : la variante est obligatoire, jamais un défaut implicite
        // silencieux — sinon un appel sans variante_id ajusterait la variante par défaut cachée
        // au lieu de la déclinaison réellement visée par l'utilisateur.
        if (empty($data['variante_id']) && $produit->variantes()->count() > 1) {
            throw ValidationException::withMessages([
                'variante_id' => 'Ce produit a plusieurs variantes : précisez laquelle ajuster.',
            ]);
        }

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

        $stockAvant = MouvementStockService::quantiteDisponible($variante->id, $site->id);
        $notes = MotifAjustementStock::from($data['motif_type'])->toNotesString($data['motif_detail'] ?? '');

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible sur ce site ({$stockAvant}).",
            ]);
        }

        $quantite = $hasAugmenter ? (int) $data['augmenter'] : (int) $data['diminuer'];
        $type = $hasAugmenter ? 'entree' : 'sortie';

        DB::transaction(function () use ($produit, $variante, $site, $type, $quantite, $notes, $user) {
            $mouvement = MouvementStockService::appliquer(
                varianteId: $variante->id,
                siteId: $site->id,
                orgId: $produit->organization_id,
                type: $type,
                quantite: $quantite,
                userId: $user->id,
                notes: $notes,
            );

            $this->auditService->record(
                $produit,
                AuditEvent::STOCK_ADJUSTED,
                $user,
                ['qte_stock' => $mouvement->stock_avant, 'site' => $site->nom],
                [
                    'qte_stock' => $mouvement->stock_apres,
                    'site' => $site->nom,
                    'motif' => $notes,
                    'role' => $user->roles->first()?->name,
                ],
            );
        });

        return back()->with('success', 'Stock mis à jour avec succès.');
    }

    /**
     * Édite une variante individuelle (prix/codes/statut) — nécessaire dès qu'un produit a
     * plusieurs déclinaisons, puisque ProduitService::mettreAJourSimple() (formulaire principal)
     * ne touche que la variante par défaut. Le SKU n'est volontairement pas éditable ici (généré
     * automatiquement, cf. ProduitVariante::booted()).
     */
    public function updateVariante(Request $request, Produit $produit, ProduitVariante $variante): RedirectResponse
    {
        $this->authorize('update', $produit);
        abort_unless($variante->produit_id === $produit->id, 404);

        $data = $request->validate([
            'code_barres' => 'nullable|string|max:100',
            'prix_usine' => 'nullable|integer|min:0',
            'prix_usine_tricycle' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'media_id' => ['nullable', Rule::exists('produit_medias', 'id')->where('produit_id', $produit->id)],
        ]);

        // Valide les prix EFFECTIFS (valeurs déjà sur la variante, écrasées par celles envoyées)
        // — même logique que ProduitService::mettreAJourSimple() pour ne pas rejeter une mise à
        // jour partielle qui ne touche pas au prix.
        $champsVariante = ['prix_usine', 'prix_usine_tricycle', 'prix_vente', 'prix_achat'];
        $donneesEffectives = array_merge(
            Arr::only($variante->getAttributes(), $champsVariante),
            Arr::only($data, $champsVariante),
        );
        $this->produitService->validerPrixSelonType($produit->produitType, $donneesEffectives);

        $variante->update($data);

        return back()->with('success', "Variante « {$variante->libelle} » mise à jour.");
    }

    /**
     * Éditeur groupé façon Shopify — vue dense de toutes les variantes d'un produit, avec
     * sélection multiple et modification en masse (cf. variantesBulkUpdate()). Le stock n'y est
     * volontairement pas éditable : il reste soumis au flux motif-tracké "Ajuster le stock"
     * (ajusterStock()) pour préserver la traçabilité des mouvements — ce n'est pas un oubli.
     */
    public function variantesIndex(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        $produit->load(['variantes.valeurs.option', 'produitType']);

        return Inertia::render('Produits/Variantes/Index', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'type_nom' => $produit->produitType?->nom,
                'prix_usine_requis' => (bool) $produit->produitType?->prix_usine_requis,
                // cf. typesOptions() : achetable/vendable pilotent la visibilité de
                // prix_achat/prix_vente, indépendamment de leur caractère obligatoire.
                'achetable' => (bool) ($produit->produitType?->achetable ?? true),
                'vendable' => (bool) ($produit->produitType?->vendable ?? true),
            ],
            'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                'id' => $v->id,
                'libelle' => $v->libelle,
                'sku' => $v->sku,
                'code_barres' => $v->code_barres,
                'prix_usine' => $v->prix_usine,
                'prix_vente' => $v->prix_vente,
                'prix_achat' => $v->prix_achat,
                'cout' => $v->cout,
                'is_default' => $v->is_default,
                'is_active' => $v->is_active,
                'options' => $this->varianteOptions($v),
            ]),
        ]);
    }

    /**
     * Modification en masse (prix/codes/statut) — jamais le SKU, généré automatiquement et
     * volontairement non éditable (même règle que updateVariante()). Chaque ligne du payload ne
     * doit porter que les variantes réellement modifiées par l'utilisateur (le frontend calcule
     * le diff), avec l'ensemble de leurs champs éditables — un update() partiel par ligne.
     */
    public function variantesBulkUpdate(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'variantes' => ['required', 'array', 'min:1'],
            'variantes.*.id' => ['required', 'string'],
            'variantes.*.code_barres' => ['nullable', 'string', 'max:100'],
            'variantes.*.prix_usine' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_usine_tricycle' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_vente' => ['nullable', 'integer', 'min:0'],
            'variantes.*.prix_achat' => ['nullable', 'integer', 'min:0'],
            'variantes.*.cout' => ['nullable', 'integer', 'min:0'],
            'variantes.*.is_active' => ['boolean'],
        ]);

        $champsVariante = ['prix_usine', 'prix_usine_tricycle', 'prix_vente', 'prix_achat'];

        DB::transaction(function () use ($produit, $data, $champsVariante) {
            foreach ($data['variantes'] as $ligne) {
                $variante = ProduitVariante::where('id', $ligne['id'])
                    ->where('produit_id', $produit->id)
                    ->firstOrFail();

                $donneesEffectives = array_merge(
                    Arr::only($variante->getAttributes(), $champsVariante),
                    Arr::only($ligne, $champsVariante),
                );
                $this->produitService->validerPrixSelonType($produit->produitType, $donneesEffectives);

                $variante->update(Arr::except($ligne, ['id']));
            }
        });

        return back()->with('success', count($data['variantes']).' variante(s) mise(s) à jour.');
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
     * Fournisseurs actifs de l'organisation, pour peupler FournisseurSelect.vue — préchargés
     * en une fois (comme categories/optionsCatalogue) plutôt qu'en recherche distante : le
     * volume attendu (prestataires d'une PME) ne justifie pas une pagination/API dédiée.
     */
    private function fournisseursOptions(string $orgId): Collection
    {
        return Fournisseur::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('raison_sociale')
            ->orderBy('nom')
            ->get()
            ->map(fn (Fournisseur $f) => [
                'id' => $f->id,
                'nom_complet' => $f->nom_complet,
                'phone' => $f->phone,
            ])
            ->values();
    }

    /**
     * Décompose une variante en paires {option, valeur} triées par position d'option puis
     * de valeur — permet au frontend de regrouper les variantes par n'importe laquelle de
     * leurs options (pattern "Regrouper par" façon Shopify) sans requête supplémentaire.
     * Nécessite variantes.valeurs.option pré-chargé par l'appelant (évite le N+1).
     *
     * @return array<int, array{option: string, valeur: string}>
     */
    private function varianteOptions(ProduitVariante $variante): array
    {
        $valeurs = $variante->valeurs->all();
        usort($valeurs, fn ($a, $b) => [$a->option->position, $a->position] <=> [$b->option->position, $b->position]);

        return array_map(fn ($v) => ['option' => $v->option->nom, 'valeur' => $v->valeur], $valeurs);
    }

    /**
     * Validation partagée create/update. Le prix requis selon le type est vérifié par
     * ProduitService::validerPrixSelonType() (appelé par le service, pas ici) pour rester
     * l'unique point de vérité partagé avec l'API.
     */
    private function validerFormulaire(Request $request, ?Produit $produit = null): array
    {
        $orgId = auth()->user()->organization_id;
        // Variante par défaut du produit édité — exclue de la contrainte d'unicité ci-dessous
        // (sinon une mise à jour qui ne touche pas au code-barres se rejetterait elle-même).
        $varianteId = $produit?->variantePrincipale()->first()?->id;

        return $request->validate([
            'nom' => 'required|string|max:255',
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'fournisseur_id' => [
                'nullable',
                Rule::exists('fournisseurs', 'id')->where('organization_id', $orgId),
            ],
            'code_barres' => [
                'nullable', 'string', 'max:100',
                Rule::unique('produit_variantes', 'code_barres')
                    ->where('organization_id', $orgId)
                    ->ignore($varianteId),
            ],
            'produit_type_id' => [
                'required',
                Rule::exists('produit_types', 'id')->where('organization_id', $orgId)->where('statut', 'actif'),
            ],
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_usine_tricycle' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            // Le formulaire (ProduitForm.vue) force toujours un choix explicite Oui/Non côté
            // UI — pas de "required" strict ici pour ne pas casser les intégrations qui
            // omettent le champ ; absent => false (défaut colonne), jamais d'alerte implicite.
            'alerte_stock_active' => 'boolean',
            'seuil_alerte_stock' => 'nullable|integer|min:1',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
            // Optionnel : déclinaisons (couleur/taille...). Absent/vide = produit simple
            // (variante par défaut invisible). Consommé par ProduitService::creer().
            'options' => 'nullable|array',
            'options.*.nom' => 'required_with:options|string|max:100',
            'options.*.valeurs' => 'required_with:options|array|min:1',
            'options.*.valeurs.*' => 'required|string|max:100',
            // Rattachement optionnel au catalogue d'options réutilisables — purement informatif
            // pour l'enrichissement du catalogue, cf. VarianteService::enrichirCatalogue().
            'options.*.option_catalogue_id' => ['nullable', Rule::exists('option_catalogues', 'id')->where('organization_id', $orgId)],
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
            'type' => $produit->produitType?->nom,
            'statut' => $produit->statut?->label(),
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'prix_usine' => $variante?->prix_usine,
            'prix_usine_tricycle' => $variante?->prix_usine_tricycle,
            'cout' => $variante?->cout,
            'qte_stock' => $produit->qte_stock,
            'seuil_alerte_stock' => $produit->seuil_alerte_stock,
            'alerte_stock_active' => $produit->alerte_stock_active,
            'description' => $produit->description,
            'code_barres' => $variante?->code_barres,
            'fournisseur' => $produit->fournisseur?->nom_complet,
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
