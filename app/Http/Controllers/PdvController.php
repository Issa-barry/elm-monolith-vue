<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Http\Requests\PdvCheckoutRequest;
use App\Models\Client;
use App\Models\Produit;
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

        $produits = $this->produitsPdv($orgId);

        $vehicules = Vehicule::with([
            'typeVehicule',
            'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur'),
        ])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('categorie', 'externe')
            ->orderBy('nom_vehicule')
            ->get()
            ->map(function (Vehicule $v) {
                $livreur = $v->equipe?->livreurs->first();

                // Import flotte ne renseigne jamais capacite_packs sur le véhicule :
                // on retombe sur la capacité par défaut du type (cf. VehiculeController).
                $capacite = $v->capacite_packs ?? $v->typeVehicule?->capacite_defaut;

                return [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'capacite_packs' => $capacite !== null ? (int) $capacite : null,
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

        $userSite = $user->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id'])
            ?? $user->sites()->first(['sites.id']);

        abort_if(! $userSite, 403, "Votre compte n'est rattaché à aucun site.");

        $commande = $this->service->checkout(
            $request->validated(),
            $user,
            $userSite->id,
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
     * Le PDV ne propose pour l'instant qu'une grille de produits (pas de sélecteur de variante
     * — Phase 3) : on affiche prix/stock/référence de la variante par défaut (ou la première)
     * comme représentative. PdvCheckoutService::resolveVariante() exigera un variante_id
     * explicite au moment de la vente pour un produit à déclinaisons multiples.
     *
     * `code_barres` est transmis en plus de `code` (référence) pour que la recherche PDV
     * (filteredProducts côté frontend) retrouve un article aussi bien par sa référence que
     * par un scan de code-barres — les deux notions sont distinctes (cf. ProduitVariante).
     */
    private function produitsPdv(string $orgId): Collection
    {
        return Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereIn('type', ProduitType::vendableValues())
            ->with(['variantes', 'medias'])
            ->orderBy('nom')
            ->get()
            ->map(function (Produit $p) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();

                return [
                    'id' => $p->id,
                    'variante_id' => $variante?->id,
                    'code' => $variante?->sku ?? '',
                    'codeBarres' => $variante?->code_barres ?? '',
                    'name' => $p->nom,
                    'subtitle' => $p->description ?? '',
                    'category' => null,
                    'stock' => (int) $p->qte_stock,
                    'unitPrice' => (int) ($variante?->prix_vente ?? 0),
                    'image' => $p->image_url ?? null,
                ];
            })->values();
    }
}
