<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Http\Requests\PdvCheckoutRequest;
use App\Models\Client;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\PdvCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PdvController extends Controller
{
    public function __construct(
        private readonly PdvCheckoutService $service,
    ) {}

    public function index(): Response
    {
        $orgId = auth()->user()->organization_id;

        $produits = $this->produitsPdv($orgId, $this->getUserSiteId());

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

    public function checkout(PdvCheckoutRequest $request): RedirectResponse
    {
        $user = auth()->user();
        $orgId = $user->organization_id;

        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSiteId = $this->getUserSiteId();

        abort_if(! $userSiteId, 403, "Votre compte n'est rattaché à aucun site.");

        $commande = $this->service->checkout(
            $request->validated(),
            $user,
            $userSiteId,
        );

        $commande->load(['lignes.variante.produit']);

        $ticket = [
            'commande_id' => $commande->id,
            'reference' => $commande->reference,
            'created_at' => $commande->created_at->format('d/m/Y H:i'),
            'org_nom' => $user->organization?->nom ?? config('app.name'),
            'total_commande' => (float) $commande->total_commande,
            'lignes' => $commande->lignes->map(fn ($l) => [
                'nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom ?? '—',
                'qte' => (int) $l->quantite_demandee,
                'prix_vente' => (int) $l->prix_vente_snapshot,
                'total' => (float) $l->total_ligne,
            ])->values()->all(),
        ];

        return redirect()->route('pdv.index')
            ->with('pdv_commande', $ticket);
    }

    /**
     * Site par défaut de l'utilisateur — même résolution que le reste de l'app (cf.
     * CommandeVenteController::getUserSiteModel()), jamais dupliquée en logique différente.
     * Retourne null plutôt que d'aborter : index() reste consultable (sans filtrage de stock)
     * même pour un utilisateur sans site, checkout() garde son propre abort_if explicite.
     */
    private function getUserSiteId(): ?string
    {
        $user = auth()->user();

        return $user->sites()->wherePivot('is_default', true)->value('sites.id')
            ?? $user->sites()->value('sites.id');
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
        $stocksParVariante = $siteId
            ? VarianteStock::where('site_id', $siteId)->whereIn('produit_variante_id', $varianteIds)->pluck('qte_stock', 'produit_variante_id')
            : collect();

        return $produits
            ->map(function (Produit $p) use ($stocksParVariante, $autoriseVenteStockNegatif, $siteId) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
                $gereStock = (bool) $p->produitType?->gere_stock;
                $disponibleSite = (int) ($stocksParVariante[$variante?->id] ?? 0);

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
