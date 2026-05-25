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

        $produits = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereIn('type', ProduitType::vendableValues())
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p) => [
                'id' => $p->id,
                'code' => $p->code_interne ?? '',
                'name' => $p->nom,
                'subtitle' => $p->description ?? '',
                'category' => null,
                'stock' => (int) $p->qte_stock,
                'unitPrice' => (int) $p->prix_vente,
                'image' => $p->image_url ?? null,
            ])->values();

        $vehicules = Vehicule::with(['equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur')])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('categorie', 'externe')
            ->orderBy('nom_vehicule')
            ->get()
            ->map(function (Vehicule $v) {
                $livreur = $v->equipe?->livreurs->first();

                return [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'capacite_packs' => $v->capacite_packs !== null ? (int) $v->capacite_packs : null,
                    'livreur_nom' => $livreur ? trim($livreur->prenom.' '.$livreur->nom) : null,
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

        return redirect()->route('ventes.show', $commande)
            ->with('success', 'Vente enregistrée avec succès.');
    }
}
