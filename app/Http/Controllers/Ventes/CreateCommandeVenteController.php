<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CreateCommandeVenteController extends Controller
{
    public function __construct(private readonly CommandeVenteFormBuilder $formBuilder) {}

    public function __invoke(): Response|RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $userSite = $this->formBuilder->getUserSiteModel();
        if ($redirect = $this->formBuilder->redirectSiCreationBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        return Inertia::render('Ventes/Create', [
            'produits' => $this->formBuilder->produitsActifs($orgId, $userSite->id),
            'vehicules' => $this->formBuilder->vehiculesActifs($orgId),
            // Pool séparé, jamais fusionné au précédent : un véhicule logistique-only
            // (livraison_vente=false) ne doit jamais être proposé pour une vente standard, ni
            // l'inverse (cf. règle métier distribution client du 31/08/2026). Le frontend choisit
            // la liste à interroger selon le type de client sélectionné.
            'vehicules_distribution' => $this->formBuilder->vehiculesLogistiques($orgId),
            'clients' => $this->formBuilder->clientsActifs($orgId),
            'user_site' => $this->formBuilder->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }
}
