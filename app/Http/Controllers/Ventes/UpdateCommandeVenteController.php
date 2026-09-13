<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AuditLogService;
use App\Services\VehiculeCommandeContextResolver;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateCommandeVenteController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly CommandeVenteFormBuilder $formBuilder,
    ) {}

    public function __invoke(Request $request, CommandeVente $vente): RedirectResponse
    {
        $this->authorize('modifierContenu', $vente);
        // Même garde-fou que edit() ci-dessus : sans lui, un super_admin pourrait réécrire les
        // lignes/total d'une commande déjà chargée/livrée/facturée via un appel API direct, en
        // s'appuyant uniquement sur le bypass Gate::before — jamais couvert par authorize() seul.
        abort_if(! $vente->isEditable(), 403, 'Cette commande ne peut plus être modifiée après le brouillon.');

        $data = $request->validate($this->formBuilder->commandeValidationRules(), $this->formBuilder->commandeValidationMessages());

        $this->formBuilder->ensureVehiculeOrClientSelected($data);
        // nature_operation est figée à la création (jamais recalculée ici, cf. NatureOperation) —
        // mais si la commande est déjà une distribution client, un changement de véhicule sur ce
        // brouillon doit continuer à respecter exactement les mêmes règles qu'à la création
        // (organisation, actif, logistique, livreur assigné), sous peine de permettre de
        // contourner la contrainte simplement en éditant plutôt qu'en créant.
        $vehiculePourValidation = $this->formBuilder->resolveVehiculeAvecEquipe($data['vehicule_id'] ?? null, $vente->organization_id);
        $this->formBuilder->ensureNatureOperationCoherente($vente->nature_operation, $data['vehicule_id'] ?? null, $vehiculePourValidation);
        $this->formBuilder->ensureQuantiteMatchesVehiculeCapacity($data);
        $client = $this->formBuilder->resolveClientForTarification($data['client_id'] ?? null);
        // Calculé ici (pur, sans effet de bord) — réutilisé par le garde-fou préventif de partage
        // commission ci-dessous ET par la persistance plus bas, jamais un second appel qui
        // pourrait diverger (même principe que store()).
        $modeRemiseGrossiste = $this->formBuilder->deriverModeRemiseGrossiste($data['vehicule_id'] ?? null, $client);
        $this->formBuilder->enforcePrixVentePolicy($data, $vente, $client);
        // Rejoue le même garde-fou qu'à la création (05/09/2026, chantier « Transfert grossiste »)
        // — sans cet appel, éditer un brouillon Enlèvement en lui affectant un véhicule (le faisant
        // ainsi basculer en Livraison) contournait entièrement la vérification de partage équipe et
        // de barème Transfert grossiste appliquée par store() : la commande n'est jamais recréée,
        // seulement modifiée, donc jamais revalidée sans cet appel explicite.
        $this->formBuilder->ensurePartageLivraisonCategorieConfigure(
            $vente->nature_operation, $vehiculePourValidation, $data['lignes'] ?? [], $client?->type, $modeRemiseGrossiste,
        );

        $vente->load(['lignes.variante.produit', 'vehicule', 'client']);
        $oldSnapshot = $this->formBuilder->commandeSnapshot($vente);

        $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $vente->nature_operation);
        [$lignesData, $totalCommande] = $this->formBuilder->buildLignesDataAndTotal($data['lignes'], $context->modeTarification, $context->categorieTarifaireVehicule, $client, $modeRemiseGrossiste);

        // Le site ne change jamais lors d'une modification de brouillon (pas de champ site_id
        // dans commandeValidationRules()) : on contrôle donc contre le site déjà porté par la
        // commande, jamais celui de l'utilisateur qui modifie (qui pourrait être différent).
        $this->formBuilder->assertStockDisponiblePourLignes($vente->organization_id, $vente->site_id, $lignesData);

        $vente->update([
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'client_vehicule_id' => $data['client_vehicule_id'] ?? null,
            'total_commande' => $totalCommande,
            'mode_tarification_snapshot' => $context->modeTarification->value,
            'commission_eligible_snapshot' => $context->commissionEligible,
            'mode_remise_grossiste' => $modeRemiseGrossiste?->value,
        ]);

        $vente->lignes()->delete();
        foreach ($lignesData as $ligneDatum) {
            $vente->lignes()->create($ligneDatum);
        }

        $vente->refresh()->load(['lignes.variante.produit', 'vehicule', 'client']);
        $newSnapshot = $this->formBuilder->commandeSnapshot($vente);

        [$oldDiff, $newDiff] = $this->auditService->diff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($vente, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('ventes.show', $vente)->with('success', 'Commande mise à jour.');
    }
}
