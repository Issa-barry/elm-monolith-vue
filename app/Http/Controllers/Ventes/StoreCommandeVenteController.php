<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Ventes\CommandeVenteCreationService;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreCommandeVenteController extends Controller
{
    public function __construct(
        private readonly CommandeVenteCreationService $creation,
        private readonly CommandeVenteFormBuilder $formBuilder,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSite = $this->formBuilder->getUserSiteModel();
        // Défense en profondeur : le bouton « Nouvelle commande » est déjà désactivé côté
        // Ventes/Index et create() refuse déjà l'accès direct à la page — ce contrôle empêche
        // en plus un POST direct (contournement de l'UI) de créer une commande sur un site sans
        // aucun stock vendable, quand la politique globale l'interdit. Même traitement que
        // create() : jamais un 403, une redirection + toast (cf. redirectSiCreationBloquee()).
        if ($redirect = $this->formBuilder->redirectSiCreationBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        $data = $request->validate($this->formBuilder->commandeValidationRules(), $this->formBuilder->commandeValidationMessages());

        // Tronc commun partagé avec la précommande (dérivations, garde-fous, impayés, prix figés,
        // lignes, audit) — cf. CommandeVenteCreationService. Seule la suite est propre à la vente.
        $commande = $this->creation->creer($data, $orgId, $userSite, function (CommandeVente $commande) {
            if ($commande->vehicule_id && $commande->lignes->isNotEmpty()) {
                CommandeVenteService::confirmer($commande);
                CommandeVenteActiviteService::log($commande, 'creation_confirmee');
            } else {
                // Vente directe client — passe en FACTURATION + crée la facture
                CommandeVenteService::creerFactureDirecte($commande);
                CommandeVenteActiviteService::log($commande, 'creation_directe');
            }
        });

        return $commande->isFacturation()
            ? redirect()->route('ventes.show', $commande)->with('success', 'Commande créée. Facture générée — en attente d\'encaissement.')
            : redirect()->route('ventes.show', $commande)->with('success', 'Commande créée et confirmée. En attente de chargement.');
    }
}
