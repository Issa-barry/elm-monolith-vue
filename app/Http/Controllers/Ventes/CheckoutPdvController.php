<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Http\Requests\PdvCheckoutRequest;
use App\Services\PdvCheckoutService;
use App\Support\Ventes\PdvSiteResolver;
use Illuminate\Http\RedirectResponse;

class CheckoutPdvController extends Controller
{
    public function __construct(
        private readonly PdvCheckoutService $service,
    ) {}

    public function __invoke(PdvCheckoutRequest $request): RedirectResponse
    {
        $user = auth()->user();
        $orgId = $user->organization_id;

        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSiteId = PdvSiteResolver::defaultSiteId();

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
}
