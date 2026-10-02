<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\ProduitStatut;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Support\Ventes\PdvSiteResolver;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class IndexPdvController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()->can('pdv.read'), 403);

        $orgId = auth()->user()->organization_id;

        $produits = $this->produitsPdv($orgId, PdvSiteResolver::defaultSiteId());

        $vehicules = Vehicule::with([
            'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur'),
        ])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->livraisonVente()
            ->orderBy('nom_vehicule')
            ->get()
            ->map(function (Vehicule $v) {
                $livreur = $v->equipe?->livreurs->first();

                return [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'livreur_nom' => $livreur?->libelleAffichage(),
                    'livreur_telephone' => $livreur?->telephone ?? null,
                ];
            })->values();

        $clients = Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'telephone'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'telephone' => $c->telephone,
            ])->values();

        return Inertia::render('PDV/Index', [
            'produits' => $produits,
            'vehicules' => $vehicules,
            'clients' => $clients,
        ]);
    }

    /**
     * Le PDV ne propose pour l'instant qu'une grille de produits (pas de sélecteur de variante
     * — Phase 3) : on affiche prix/stock/référence de la variante par défaut (ou la première)
     * comme représentative. PdvCheckoutService::resolveVariante() exigera un variante_id
     * explicite au moment de la vente pour un produit à déclinaisons multiples.
     *
     * `code_barres` est transmis en plus de `code` (référence) pour que la recherche PDV
     * (filteredProducts côté frontend) retrouve un article aussi bien par sa référence que
     * par un scan de code-barres — les deux notions sont distinctes (cf. ProduitVariante).
     *
     * $siteId : quand fourni ET que la politique globale interdit la vente sans stock
     * (Parametre::isVentesAutoriseesSansStock() = false), un produit géré en stock sans aucun
     * disponible sur CE site est exclu de la grille — jamais sur l'agrégat global du produit
     * (décision produit du 24/08/2026). Le champ `stock` affiché devient aussi le stock réel du
     * site courant plutôt que l'agrégat legacy, pour ne jamais afficher un nombre trompeur à
     * côté d'une grille désormais filtrée par site.
     */
    private function produitsPdv(string $orgId, ?string $siteId): Collection
    {
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($orgId);

        $produits = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereHas('produitType', fn ($q) => $q->where('vendable', true))
            ->with(['variantes', 'medias', 'produitType'])
            ->orderBy('nom')
            ->get();

        $varianteIds = $produits->flatMap(fn (Produit $p) => $p->variantes->pluck('id'))->all();
        // Disponible = physique − engagé (StockReservationService, 25/08/2026) : la grille PDV
        // affichait auparavant le stock physique brut comme « stock », permettant de vendre un
        // article déjà entièrement engagé par une commande vente confirmée en attente de
        // chargement — exactement le scénario que la réservation doit empêcher.
        $stocksParVariante = $siteId
            ? VarianteStock::where('site_id', $siteId)->whereIn('produit_variante_id', $varianteIds)->get(['produit_variante_id', 'qte_stock', 'qte_reservee'])->keyBy('produit_variante_id')
            : collect();

        return $produits
            ->map(function (Produit $p) use ($stocksParVariante, $autoriseVenteStockNegatif, $siteId) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
                $gereStock = (bool) $p->produitType?->gere_stock;
                $stock = $stocksParVariante[$variante?->id] ?? null;
                $disponibleSite = $stock ? ((int) $stock->qte_stock - (int) $stock->qte_reservee) : 0;

                if ($siteId && $gereStock && ! $autoriseVenteStockNegatif && $disponibleSite <= 0) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'variante_id' => $variante?->id,
                    'code' => $variante?->sku ?? '',
                    'codeBarres' => $variante?->code_barres ?? '',
                    'name' => $p->nom,
                    'subtitle' => $p->description ?? '',
                    'category' => null,
                    'stock' => $siteId && $gereStock ? $disponibleSite : (int) $p->qte_stock,
                    'unitPrice' => (int) ($variante?->prix_vente ?? 0),
                    'image' => $p->image_url ?? null,
                ];
            })
            ->filter()
            ->values();
    }
}
