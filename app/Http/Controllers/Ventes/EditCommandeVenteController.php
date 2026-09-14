<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Inertia\Inertia;
use Inertia\Response;

class EditCommandeVenteController extends Controller
{
    public function __construct(private readonly CommandeVenteFormBuilder $formBuilder) {}

    public function __invoke(CommandeVente $vente): Response
    {
        $this->authorize('modifierContenu', $vente);
        // Garde-fou explicite en plus de authorize() : Gate::before (AuthServiceProvider)
        // bypasse modifierContenu() — donc isEditable() — pour super_admin, qui verrait sinon le
        // formulaire d'édition sur une commande déjà sortie de BROUILLON.
        abort_if(! $vente->isEditable(), 403, 'Cette commande ne peut plus être modifiée après le brouillon.');

        $orgId = auth()->user()->organization_id;
        $vente->load(['lignes.variante']);

        return Inertia::render('Ventes/Edit', [
            'commande' => [
                'id' => $vente->id,
                'reference' => $vente->reference,
                'vehicule_id' => $vente->vehicule_id,
                'client_id' => $vente->client_id,
                'client_vehicule_id' => $vente->client_vehicule_id,
                'mode_remise_grossiste' => $vente->mode_remise_grossiste?->value,
                'lignes' => $vente->lignes->map(fn ($l) => [
                    // Bridge Phase 3 : le formulaire actuel ne sélectionne qu'un produit
                    // (pas de sélecteur de variante), on retrouve donc le produit parent.
                    'produit_id' => $l->variante?->produit_id,
                    'variante_id' => $l->variante_id,
                    'qte' => (int) $l->quantite_demandee,
                    'prix_vente' => (float) $l->prix_vente_snapshot,
                ]),
            ],
            'produits' => $this->formBuilder->produitsActifs($orgId),
            'vehicules' => $this->formBuilder->vehiculesActifs($orgId),
            'clients' => $this->formBuilder->clientsActifs($orgId),
            'user_site' => $this->formBuilder->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }
}
