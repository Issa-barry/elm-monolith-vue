<?php

namespace App\Services\Ventes;

use App\Enums\AuditEvent;
use App\Enums\NatureOperation;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\SolvabiliteService;
use App\Services\VehiculeCommandeContextResolver;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Création d'une commande de vente à partir d'une saisie déjà validée — tronc commun de la vente
 * (Ventes\StoreCommandeVenteController) et de la précommande (Ventes\StorePrecommandeController,
 * ADR 0019) : mêmes dérivations (nature, mode Grossiste, contexte tarifaire), mêmes garde-fous,
 * même contrôle des impayés, mêmes prix figés. Seule la suite diffère, passée par l'appelant
 * (`$finaliser`) et exécutée dans la MÊME transaction : tout échec annule la commande entière.
 */
final class CommandeVenteCreationService
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly SolvabiliteService $solvabiliteService,
        private readonly CommandeVenteFormBuilder $formBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $data  saisie validée (CommandeVenteFormBuilder::commandeValidationRules())
     * @param  callable(CommandeVente): void  $finaliser  suite propre à l'appelant, dans la transaction
     * @param  array<string, mixed>  $attributs  attributs supplémentaires de la commande
     * @param  bool  $stockStrict  contrôle de disponibilité même si l'organisation autorise la vente
     *                             sans stock (précommande : jamais de réservation à découvert)
     */
    public function creer(array $data, string $orgId, Site $site, callable $finaliser, array $attributs = [], bool $stockStrict = false): CommandeVente
    {
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
        $this->formBuilder->ensureVehiculeAutorisePourGrossiste($modeRemiseGrossiste, $vehiculePourValidation);
        $this->formBuilder->ensureQuantiteMatchesVehiculeCapacity($data);
        $this->formBuilder->enforcePrixVentePolicy($data, null, $client);
        $this->formBuilder->ensurePartageLivraisonCategorieConfigure(
            $natureOperation, $vehiculePourValidation, $data['lignes'] ?? [], $client?->type, $modeRemiseGrossiste,
        );

        return DB::transaction(function () use ($data, $orgId, $site, $client, $natureOperation, $modeRemiseGrossiste, $finaliser, $attributs, $stockStrict) {
            // Verrou de ligne sur le véhicule le temps de la transaction : sans cela, deux
            // requêtes concurrentes pour le même véhicule (double clic, deux utilisateurs)
            // passeraient toutes les deux le contrôle des impayés avant qu'aucune des deux
            // commandes ne soit créée, puis créeraient chacune une commande — exactement le
            // doublon que ce contrôle doit empêcher. Le verrou est acquis AVANT le contrôle pour
            // que la seconde requête, bloquée jusqu'au commit de la première, revoie bien la
            // facture fraîchement créée par celle-ci une fois débloquée.
            if (! empty($data['vehicule_id'])) {
                Vehicule::whereKey($data['vehicule_id'])->lockForUpdate()->first();
            }

            // Seul appel à SolvabiliteService::enforcerOuEchouer() côté back-office — voir
            // PdvCheckoutService::checkout() pour l'appelant équivalent côté PDV.
            $this->solvabiliteService->enforcerOuEchouer($orgId, $data['vehicule_id'] ?? null, $data['client_id'] ?? null);

            $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $natureOperation);
            [$lignesData, $totalCommande] = $this->formBuilder->buildLignesDataAndTotal($data['lignes'], $context->modeTarification, $context->categorieTarifaireVehicule, $client, $modeRemiseGrossiste);

            $this->formBuilder->assertStockDisponiblePourLignes($orgId, $site->id, $lignesData, $stockStrict);

            $commande = CommandeVente::create([
                ...$attributs,
                'organization_id' => $orgId,
                'site_id' => $site->id,
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

            $finaliser($commande);

            return $commande;
        });
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
