<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulaire « Nouvelle précommande » (ADR 0019) : même page que la vente (Ventes/Create), mais le
 * contexte précommande est imposé par CETTE route — jamais un sélecteur dans le formulaire, pour
 * qu'un agent ne crée pas une vente en croyant faire une précommande (ou l'inverse).
 */
class CreatePrecommandeController extends Controller
{
    public function __construct(
        private readonly CommandeVenteFormBuilder $formBuilder,
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
    ) {}

    public function __invoke(): Response|RedirectResponse
    {
        $this->authorize('precommander', CommandeVente::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $userSite = $this->formBuilder->getUserSiteModel();
        if ($redirect = $this->formBuilder->redirectSiPrecommandeBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        return Inertia::render('Ventes/Create', [
            // Disponible strict : un produit sans stock disponible n'est jamais proposé, même si
            // l'organisation autorise la vente sans stock (décision D3).
            'produits' => $this->formBuilder->produitsActifs($orgId, $userSite->id, stockStrict: true),
            'vehicules' => $this->formBuilder->vehiculesActifs($orgId),
            'vehicules_distribution' => $this->formBuilder->vehiculesLogistiques($orgId),
            'clients' => $this->formBuilder->clientsActifs($orgId),
            'user_site' => $this->formBuilder->getUserSite(),
            'can_modifier_qte' => $user->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
            'precommande' => [
                'acompte_obligatoire' => Parametre::isPrecommandeAcompteObligatoire($orgId),
                'acompte_min_pct' => Parametre::getPrecommandeAcompteMinPct($orgId),
                // L'acompte est toujours reçu par l'agence de la précommande (décision D11) : moyens
                // et caisse dédiée de CETTE agence, mêmes sources que l'écran d'encaissement.
                'moyens_encaissement' => $this->moyens->pourSite($orgId, $userSite->id),
                'peut_encaisser_especes' => $this->caisses->caisseActive($orgId, (string) $user->id, $userSite->id) !== null,
            ],
        ]);
    }
}
