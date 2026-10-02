<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Enums\NatureOperation;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\SolvabiliteService;
use App\Services\VehiculeCommandeContextResolver;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreCommandeVenteController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly SolvabiliteService $solvabiliteService,
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

        $this->formBuilder->ensureVehiculeOrClientSelected($data);

        $client = $this->formBuilder->resolveClientForTarification($data['client_id'] ?? null);
        // Chargé une seule fois (organisation vérifiée, équipe/chauffeur actif eager-chargés) —
        // sert à dériver nature_operation, à la valider (ensureNatureOperationCoherente()) et au
        // pré-contrôle de partage commission (ensurePartageLivraisonCategorieConfigure()), jamais
        // trois requêtes/dérivations séparées comme avant le 31/08/2026.
        $vehiculePourValidation = $this->formBuilder->resolveVehiculeAvecEquipe($data['vehicule_id'] ?? null, $orgId);
        $natureOperation = $this->resoudreNatureOperation($data, $client, $vehiculePourValidation);
        // Calculé une seule fois ici (pur, sans effet de bord) — réutilisé par le garde-fou
        // préventif de partage commission ci-dessous ET par la persistance dans la transaction,
        // jamais un second appel qui pourrait diverger (même principe que resoudreNatureOperation()).
        $modeRemiseGrossiste = $this->formBuilder->deriverModeRemiseGrossiste($data['vehicule_id'] ?? null, $client);

        $this->formBuilder->ensureNatureOperationCoherente($natureOperation, $data['vehicule_id'] ?? null, $vehiculePourValidation);
        $this->formBuilder->ensureQuantiteMatchesVehiculeCapacity($data);
        $this->formBuilder->enforcePrixVentePolicy($data, null, $client);
        $this->formBuilder->ensurePartageLivraisonCategorieConfigure(
            $natureOperation, $vehiculePourValidation, $data['lignes'] ?? [], $client?->type, $modeRemiseGrossiste,
        );

        $commande = DB::transaction(function () use ($data, $orgId, $userSite, $client, $natureOperation, $modeRemiseGrossiste) {
            // Verrou de ligne sur le véhicule le temps de la transaction : sans cela, deux
            // requêtes concurrentes pour le même véhicule (double clic, deux utilisateurs)
            // passeraient toutes les deux enforceImpayesBlocking() avant qu'aucune des deux
            // commandes ne soit créée, puis créeraient chacune une commande — exactement le
            // doublon que ce contrôle doit empêcher (cf. section « concurrence » de la règle
            // métier). Le verrou est acquis AVANT le contrôle pour que la seconde requête,
            // bloquée par MySQL/Postgres jusqu'au commit de la première, revoie bien la facture
            // fraîchement créée par celle-ci une fois débloquée.
            if (! empty($data['vehicule_id'])) {
                Vehicule::whereKey($data['vehicule_id'])->lockForUpdate()->first();
            }

            $this->enforceImpayesBlocking($data, $orgId);

            $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $natureOperation);
            [$lignesData, $totalCommande] = $this->formBuilder->buildLignesDataAndTotal($data['lignes'], $context->modeTarification, $context->categorieTarifaireVehicule, $client, $modeRemiseGrossiste);

            $this->formBuilder->assertStockDisponiblePourLignes($orgId, $userSite->id, $lignesData);

            $commande = CommandeVente::create([
                'organization_id' => $orgId,
                'site_id' => $userSite->id,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'client_vehicule_id' => $data['client_vehicule_id'] ?? null,
                'total_commande' => $totalCommande,
                'mode_tarification_snapshot' => $context->modeTarification->value,
                'commission_eligible_snapshot' => $context->commissionEligible,
                'nature_operation' => $natureOperation->value,
                'mode_remise_grossiste' => $modeRemiseGrossiste?->value,
                'created_by' => auth()->id(),
            ]);

            foreach ($lignesData as $ligneDatum) {
                $commande->lignes()->create($ligneDatum);
            }

            $commande->load(['lignes.variante.produit', 'vehicule', 'client']);
            $this->auditService->record($commande, AuditEvent::CREATED, auth()->user(), null, $this->formBuilder->commandeSnapshot($commande));

            if ($commande->vehicule_id && $commande->lignes->isNotEmpty()) {
                CommandeVenteService::confirmer($commande);
                CommandeVenteActiviteService::log($commande, 'creation_confirmee');
            } else {
                // Vente directe client — passe en FACTURATION + crée la facture
                CommandeVenteService::creerFactureDirecte($commande);
                CommandeVenteActiviteService::log($commande, 'creation_directe');
            }

            return $commande;
        });

        return $commande->isFacturation()
            ? redirect()->route('ventes.show', $commande)->with('success', 'Commande créée. Facture générée — en attente d\'encaissement.')
            : redirect()->route('ventes.show', $commande)->with('success', 'Commande créée et confirmée. En attente de chargement.');
    }

    /**
     * Seul appelant de SolvabiliteService::enforcerOuEchouer() côté back-office — voir
     * PdvCheckoutService::checkout() pour l'appelant équivalent côté PDV, sur exactement le
     * même service (jamais de calcul dupliqué).
     */
    private function enforceImpayesBlocking(array $data, string $orgId): void
    {
        $this->solvabiliteService->enforcerOuEchouer(
            $orgId,
            $data['vehicule_id'] ?? null,
            $data['client_id'] ?? null,
        );
    }

    /**
     * Nature effectivement retenue pour la commande — explicite si soumise, dérivée sinon
     * (NatureOperation::deriverParDefaut(), seule source de vérité). Calculée une seule fois,
     * puis réutilisée pour la validation de cohérence ET la persistance : jamais un second appel
     * à deriverParDefaut() qui pourrait diverger du premier.
     */
    private function resoudreNatureOperation(array $data, ?Client $client, ?Vehicule $vehicule): NatureOperation
    {
        return isset($data['nature_operation'])
            ? NatureOperation::from($data['nature_operation'])
            : NatureOperation::deriverParDefaut($client?->type, $vehicule);
    }
}
