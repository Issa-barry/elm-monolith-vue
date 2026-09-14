<?php

namespace App\Http\Controllers\Produits;

use App\Enums\ProduitStatut;
use App\Enums\StockStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\DroitAjustementStockService;
use App\Services\StockStatutService;
use App\Support\Produits\ProduitFormOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexProduitController extends Controller
{
    public function __construct(
        private readonly DroitAjustementStockService $droitService,
        private readonly StockStatutService $stockStatutService,
    ) {}

    public function __invoke(Request $request): Response
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
                'seuilsAlerte',
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
            // d'identifiant externe que côté PDV (cf. Ventes\IndexPdvController::produitsPdv()).
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

            // Statut résolu via statutPour() (fonction PURE, seulement qte/seuil — le stock
            // physique reste réel, cf. StockStatutService) pour CHAQUE site, puis filtré pour
            // les badges "alerte" de cette page (bannière rupture/stock faible) aux sites à la
            // fois DISPONIBLES (ce produit y est réellement vendu/géré) ET ALERTE ACTIVE (choix
            // explicite de surveillance) — jamais à un total agrégé ni à la configuration d'un
            // AUTRE site (cf. décision produit : un stock élevé ailleurs ne doit jamais masquer
            // une alerte locale). Un site non disponible ou sans alerte peut être en rupture
            // physique réelle sans jamais alimenter ces badges — cf. stocks_par_site ci-dessous
            // pour l'état réel, affiché indépendamment de ce filtre.
            if ($hasStock && $siteStocksScope->isNotEmpty()) {
                $statutsAlerteParSite = $siteStocksScope
                    ->filter(fn ($s) => $this->stockStatutService->disponiblePourSite($p, $s->site_id)
                        && $this->stockStatutService->alerteActivePourSite($p, $s->site_id))
                    ->map(fn ($s) => $this->stockStatutService->statutPour(
                        $s->qte_stock,
                        $this->stockStatutService->seuilEffectifPourSite($p, $s->site_id),
                    ));
                $isRupture = $statutsAlerteParSite->contains(fn (StockStatut $s) => in_array($s, [StockStatut::RUPTURE, StockStatut::STOCK_NEGATIF], true));
                $isLowStock = $statutsAlerteParSite->contains(StockStatut::STOCK_FAIBLE);
            } elseif ($hasStock) {
                // Aucune ligne VarianteStock pour ce produit : aucun site connu, donc aucune
                // configuration par site ne peut s'appliquer — repli sur le seuil global, jamais
                // d'alerte possible (aucun site n'a été explicitement activé pour l'alerte).
                $isRupture = false;
                $isLowStock = false;
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
                'qte_stock' => $qteDisplay,
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
                    // Statut réel (fonction pure, cf. StockStatutService::statutPour()) — jamais
                    // masqué par la disponibilité/l'alerte. `disponible_sur_site` indique au
                    // frontend d'afficher "Non disponible" à la place du statut coloré quand ce
                    // produit n'est pas géré sur ce site (pas de rupture "métier" possible).
                    'statut' => ($hasStock ? $this->stockStatutService->statutPour($s->qte_stock, $this->stockStatutService->seuilEffectifPourSite($p, $s->site_id)) : StockStatut::DISPONIBLE)->value,
                    'disponible_sur_site' => $this->stockStatutService->disponiblePourSite($p, $s->site_id),
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
            'types' => ProduitFormOptions::types($orgId),
            'statuts' => ProduitStatut::options(),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
        ]);
    }
}
