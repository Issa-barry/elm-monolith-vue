<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\StockStatut;
use App\Models\Categorie;
use App\Models\MouvementStock;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\User;
use App\Services\DroitAjustementStockService;
use App\Services\MouvementStockMotifService;
use App\Services\StockStatutService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function __construct(
        private readonly DroitAjustementStockService $droitService,
        private readonly StockStatutService $stockStatutService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Produit::class);

        $user = $request->user();
        $orgId = (string) $user->organization_id;
        $sites = $this->sitesConsultables($user, $orgId);
        $siteIds = $this->siteIdsFiltres($request, $sites, $user->isAdmin());
        $seuilOrganisation = Parametre::getSeuilStockFaible($orgId);

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'categorie_id' => $this->premiereValeur($request->input('categorie_id')),
            'stock_statut' => $this->premiereValeur($request->input('stock_statut')),
            'site_ids' => $siteIds,
        ];

        $sitesAjustables = $this->droitService->canAjuster($user, $orgId)
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $sites)
            : collect();
        $sitesAjustablesIds = $sitesAjustables->pluck('id')->map(fn ($id) => (string) $id)->values();

        $query = $this->stockQuery($orgId, $siteIds, $seuilOrganisation, $filters);
        $stocks = $query->paginate(25)->withQueryString();

        $this->enrichirLignes($stocks, $orgId, $sitesAjustablesIds);

        return Inertia::render('Produits/Stock/Index', [
            'stocks' => $stocks,
            'sites' => $sites->values(),
            'categories' => Categorie::where('organization_id', $orgId)
                ->where('statut', 'actif')
                ->orderBy('nom')
                ->get(['id', 'nom']),
            'stock_statuts' => collect(StockStatut::cases())->map(fn (StockStatut $statut) => [
                'value' => $statut->value,
                'label' => $statut->label(),
            ]),
            'filters' => $filters,
            'can_augmenter_stock' => $this->droitService->canAugmenter($user, $orgId),
            'can_diminuer_stock' => $this->droitService->canDiminuer($user, $orgId),
        ]);
    }

    private function stockQuery(string $orgId, array $siteIds, int $seuilOrganisation, array $filters): Builder
    {
        $physique = 'COALESCE(vs.qte_stock, 0)';
        $reserve = 'COALESCE(vs.qte_reservee, 0)';
        // « Disponible » = ce qui reste réellement vendable — physique moins réservé (commandes
        // vente confirmées, pas encore chargées, cf. StockReservationService). L'État affiché
        // ci-dessous se base sur CETTE quantité, jamais le physique brut : un stock physique
        // positif mais entièrement réservé doit apparaître en Rupture, pas Disponible (24/08/2026,
        // même bug que la page Commande CMD-* : deux commandes pouvaient toutes deux promettre le
        // même stock tant que rien n'était retenu entre la confirmation et le chargement).
        $quantite = "({$physique} - {$reserve})";
        $seuil = 'COALESCE(p.seuil_alerte_stock, ?)';

        $query = DB::table('produit_variantes as pv')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->join('sites as s', function ($join) use ($orgId, $siteIds) {
                $join->where('s.organization_id', '=', $orgId)
                    ->whereNull('s.deleted_at')
                    ->whereIn('s.id', $siteIds);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.categorie_id')
            ->leftJoin('variante_stocks as vs', function ($join) {
                $join->on('vs.produit_variante_id', '=', 'pv.id')
                    ->on('vs.site_id', '=', 's.id');
            })
            ->where('p.organization_id', $orgId)
            ->where('pv.organization_id', $orgId)
            ->where('pt.organization_id', $orgId)
            ->where('pt.gere_stock', true)
            ->where('p.statut', '!=', ProduitStatut::ARCHIVE->value)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select([
                'p.id as produit_id',
                'p.nom as produit_nom',
                'p.categorie_id',
                'c.nom as categorie_nom',
                'p.alerte_stock_active',
                'p.seuil_alerte_stock',
                'pv.id as variante_id',
                'pv.sku',
                'pv.is_default',
                'pv.combo_hash',
                's.id as site_id',
                's.nom as site_nom',
                's.code as site_code',
            ])
            ->selectRaw("{$quantite} as qte_disponible")
            ->selectRaw("{$physique} as qte_physique")
            ->selectRaw("{$reserve} as qte_reservee")
            ->selectRaw("{$seuil} as seuil_effectif", [$seuilOrganisation]);

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where('p.nom', 'like', "%{$search}%")
                ->orWhere('pv.sku', 'like', "%{$search}%"));
        }

        if ($filters['categorie_id']) {
            $query->where('p.categorie_id', $filters['categorie_id']);
        }

        match ($filters['stock_statut']) {
            StockStatut::STOCK_NEGATIF->value => $query->whereRaw("{$quantite} < 0"),
            StockStatut::RUPTURE->value => $query->whereRaw("{$quantite} = 0"),
            StockStatut::STOCK_FAIBLE->value => $query
                ->where('p.alerte_stock_active', true)
                ->whereRaw("{$quantite} > 0")
                ->whereRaw("{$seuil} > 0", [$seuilOrganisation])
                ->whereRaw("{$quantite} <= {$seuil}", [$seuilOrganisation]),
            StockStatut::DISPONIBLE->value => $query->where(function (Builder $q) use ($quantite, $seuil, $seuilOrganisation) {
                $q->whereRaw("{$quantite} > {$seuil}", [$seuilOrganisation])
                    ->orWhere('p.alerte_stock_active', false)
                    ->orWhereRaw("{$seuil} <= 0", [$seuilOrganisation]);
            })->whereRaw("{$quantite} > 0"),
            default => null,
        };

        return $query
            ->orderBy('p.nom')
            ->orderBy('pv.position')
            ->orderBy('s.nom');
    }

    private function enrichirLignes(LengthAwarePaginator $stocks, string $orgId, Collection $sitesAjustablesIds): void
    {
        $rows = collect($stocks->items());
        $varianteIds = $rows->pluck('variante_id')->unique()->values();
        $siteIds = $rows->pluck('site_id')->unique()->values();

        $variantes = ProduitVariante::with('valeurs.option')
            ->where('organization_id', $orgId)
            ->whereIn('id', $varianteIds)
            ->get()
            ->keyBy('id');

        $derniersMouvements = MouvementStock::where('organization_id', $orgId)
            ->whereIn('produit_variante_id', $varianteIds)
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('created_at')
            ->get(['id', 'produit_variante_id', 'site_id', 'type', 'quantite', 'source_type', 'source_id', 'notes', 'created_at'])
            ->unique(fn (MouvementStock $mouvement) => $mouvement->produit_variante_id.'|'.$mouvement->site_id)
            ->keyBy(fn (MouvementStock $mouvement) => $mouvement->produit_variante_id.'|'.$mouvement->site_id);

        // Motif + source (« Vente CMD-… », « Transfert TR-… », ou le motif saisi pour un
        // ajustement manuel) — jamais déduit du seul signe/montant du mouvement, cf. AGENTS.md
        // §6. Réutilise MouvementStockMotifService::annoter(), déjà utilisé par la modale
        // Historique, plutôt que de dupliquer cette résolution ici.
        MouvementStockMotifService::annoter($derniersMouvements);

        $stocks->setCollection($rows->map(function ($row) use ($variantes, $derniersMouvements, $sitesAjustablesIds) {
            $variante = $variantes->get($row->variante_id);
            // L'état (Rupture / Stock faible / Disponible) se base sur le DISPONIBLE — jamais le
            // physique brut : un stock physique positif mais entièrement réservé par des
            // commandes confirmées n'est plus vendable, il ne doit donc jamais afficher
            // « Disponible » (cf. commentaire de stockQuery()).
            $statut = $this->stockStatutService->statutPour(
                (int) $row->qte_disponible,
                (int) $row->seuil_effectif,
                (bool) $row->alerte_stock_active,
            );
            $dernierMouvement = $derniersMouvements->get($row->variante_id.'|'.$row->site_id);

            return [
                'produit_id' => $row->produit_id,
                'produit_nom' => $row->produit_nom,
                'categorie_id' => $row->categorie_id,
                'categorie_nom' => $row->categorie_nom,
                'variante_id' => $row->variante_id,
                'variante_libelle' => $this->libelleVariante($variante),
                'is_default' => (bool) $row->is_default,
                'sku' => $row->sku,
                'site_id' => $row->site_id,
                'site_nom' => $row->site_nom,
                'site_code' => $row->site_code,
                'qte_disponible' => (int) $row->qte_disponible,
                'qte_physique' => (int) $row->qte_physique,
                'qte_engagee' => (int) $row->qte_reservee,
                // Bloqué et Entrant ne sont pas encore calculés côté backend (Lot 3 / Lot 2) —
                // explicitement null, jamais 0 : un 0 laisserait croire à un contrôle déjà fait
                // (cf. audit stock du 25/08/2026, « ne jamais afficher de fausse valeur »).
                'qte_bloquee' => null,
                'qte_entrante' => null,
                'seuil_effectif' => (int) $row->seuil_effectif,
                'statut' => $statut->value,
                'statut_label' => $statut->label(),
                'dernier_mouvement' => $dernierMouvement ? [
                    'type' => $dernierMouvement->type,
                    'quantite' => (int) $dernierMouvement->quantite,
                    'motif_label' => $dernierMouvement->motif_label,
                    'created_at' => $dernierMouvement->created_at?->format('d/m/Y H:i'),
                ] : null,
                'can_ajuster' => $sitesAjustablesIds->contains((string) $row->site_id),
            ];
        }));
    }

    private function sitesConsultables(User $user, string $orgId): Collection
    {
        $query = Site::where('organization_id', $orgId)->orderBy('nom');

        if (! $user->isAdmin()) {
            $query->whereIn('id', $user->sites()->where('sites.organization_id', $orgId)->pluck('sites.id'));
        }

        return $query->get(['id', 'nom', 'code']);
    }

    private function libelleVariante(?ProduitVariante $variante): string
    {
        if (! $variante || $variante->combo_hash === ProduitVariante::COMBO_HASH_DEFAUT) {
            return 'Variante par défaut';
        }

        $libelle = $variante->valeurs
            ->sort(fn ($a, $b) => [
                $a->option?->position ?? 0,
                $a->position,
            ] <=> [
                $b->option?->position ?? 0,
                $b->position,
            ])
            ->pluck('valeur')
            ->implode(' / ');

        return $libelle !== '' ? $libelle : 'Variante';
    }

    private function siteIdsFiltres(Request $request, Collection $sites, bool $isAdmin): array
    {
        $consultables = $sites->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (! $isAdmin) {
            return $consultables;
        }

        $demandes = array_values(array_filter(array_map('strval', (array) $request->input('site_ids', []))));

        return $demandes === [] ? $consultables : array_values(array_intersect($consultables, $demandes));
    }

    private function premiereValeur(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn ($item) => $item !== null && $item !== '');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
