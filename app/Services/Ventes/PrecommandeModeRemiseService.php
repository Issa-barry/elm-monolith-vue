<?php

namespace App\Services\Ventes;

use App\Enums\AuditEvent;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\SolvabiliteService;
use App\Services\VehiculeCommandeContextResolver;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changement du mode de remise d'une précommande — retrait ↔ livraison (ADR 0019, décision D16) :
 * le client change d'avis avant que la marchandise ne sorte. Le mode reste dérivé du véhicule (C7) :
 * changer de mode, c'est fixer ou retirer le véhicule, avec les mêmes dérivations et garde-fous qu'à
 * la création (CommandeVenteCreationService). Refusé si le prix ou la nature de l'opération
 * changeraient : la précommande s'annule alors et se recrée.
 */
final class PrecommandeModeRemiseService
{
    /** Avant le démarrage du chargement et avant le retrait : rien n'est sorti du stock. */
    public const STATUTS_MODIFIABLES = [
        StatutCommandeVente::RESERVEE,
        StatutCommandeVente::A_PREPARER,
        StatutCommandeVente::PREPAREE,
        StatutCommandeVente::A_CHARGER,
    ];

    public function __construct(
        private readonly CommandeVenteFormBuilder $formBuilder,
        private readonly SolvabiliteService $solvabilite,
        private readonly AuditLogService $audit,
    ) {}

    public static function estModifiable(CommandeVente $commande): bool
    {
        return $commande->est_precommande
            && $commande->remise_at === null
            && in_array($commande->statut, self::STATUTS_MODIFIABLES, true);
    }

    /** @param  string|null  $vehiculeId  véhicule de livraison, null pour passer en retrait */
    public function changer(CommandeVente $commande, ?string $vehiculeId): void
    {
        abort_if(! $commande->est_precommande, 422, "Cette commande n'est pas une précommande.");
        abort_if(! self::estModifiable($commande), 422, 'Le mode de remise ne peut plus changer une fois le chargement démarré ou la marchandise remise.');

        $versLivraison = $vehiculeId !== null;
        abort_if($versLivraison === ($commande->vehicule_id !== null), 422, $versLivraison
            ? 'Cette précommande est déjà en livraison.'
            : 'Cette précommande est déjà en retrait.');

        $orgId = $commande->organization_id;
        $commande->load('lignes.variante');
        $client = $this->formBuilder->resolveClientForTarification($commande->client_id);
        $vehicule = $this->formBuilder->resolveVehiculeAvecEquipe($vehiculeId, $orgId);

        if ($versLivraison && (! $vehicule || ! $vehicule->is_active)) {
            throw ValidationException::withMessages(['vehicule_id' => 'Ce véhicule est introuvable ou inactif pour votre organisation.']);
        }

        $nature = NatureOperation::deriverParDefaut($client?->type, $vehicule);
        if ($nature !== $commande->nature_operation) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce changement ferait passer l'opération de « {$commande->nature_operation->label()} » à « {$nature->label()} » : annulez la précommande et recréez-la.",
            ]);
        }

        $lignes = self::lignesSaisie($commande);

        $modeRemiseGrossiste = $this->formBuilder->deriverModeRemiseGrossiste($vehiculeId, $client);
        $this->formBuilder->ensureNatureOperationCoherente($nature, $vehiculeId, $vehicule);
        $this->formBuilder->ensureVehiculeAutorisePourGrossiste($modeRemiseGrossiste, $vehicule);
        $this->formBuilder->ensureQuantiteMatchesVehiculeCapacity(['vehicule_id' => $vehiculeId, 'lignes' => $lignes]);
        $this->formBuilder->ensurePartageLivraisonCategorieConfigure($nature, $vehicule, $lignes, $client?->type, $modeRemiseGrossiste);

        DB::transaction(function () use ($commande, $vehiculeId, $vehicule, $versLivraison, $client, $nature, $modeRemiseGrossiste, $orgId) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $commande->load('lignes.variante');
            $lignes = self::lignesSaisie($commande);
            abort_if(! self::estModifiable($commande), 422, 'Le mode de remise ne peut plus changer une fois le chargement démarré ou la marchandise remise.');

            if ($vehiculeId) {
                // Même verrou et même contrôle des impayés du véhicule qu'à la création.
                Vehicule::whereKey($vehiculeId)->lockForUpdate()->first();
                $this->solvabilite->enforcerOuEchouer($orgId, $vehiculeId, $commande->client_id);
            }

            $context = VehiculeCommandeContextResolver::resolve($vehiculeId, $commande->client_id, $nature);
            [$nouvellesLignes] = $this->formBuilder->buildLignesDataAndTotal($lignes, $context->modeTarification, $context->categorieTarifaireVehicule, $client, $modeRemiseGrossiste);

            foreach ($commande->lignes->values() as $i => $ligne) {
                if (abs((float) $nouvellesLignes[$i]['total_ligne'] - (float) $ligne->total_ligne) > 0.01) {
                    throw ValidationException::withMessages([
                        'vehicule_id' => 'Le prix de la précommande changerait avec ce mode de remise ('
                            .$ligne->libelle_snapshot.') : annulez la précommande et recréez-la.',
                    ]);
                }
            }

            $avant = $this->instantane($commande);

            // Prix inchangés : seules les références de tarification suivent le nouveau mode.
            foreach ($commande->lignes->values() as $i => $ligne) {
                $ligne->update([
                    'prix_usine_snapshot' => $nouvellesLignes[$i]['prix_usine_snapshot'],
                    'prix_vente_snapshot' => $nouvellesLignes[$i]['prix_vente_snapshot'],
                    'prix_origine_snapshot' => $nouvellesLignes[$i]['prix_origine_snapshot'],
                ]);
            }

            $statut = match ($commande->statut) {
                StatutCommandeVente::PREPAREE => StatutCommandeVente::A_CHARGER,
                StatutCommandeVente::A_CHARGER => StatutCommandeVente::PREPAREE,
                default => $commande->statut,
            };

            $commande->update([
                'vehicule_id' => $vehiculeId,
                'mode_tarification_snapshot' => $context->modeTarification->value,
                'commission_eligible_snapshot' => $context->commissionEligible,
                'mode_remise_grossiste' => $modeRemiseGrossiste?->value,
                'statut' => $statut,
                'a_charger_at' => $statut === StatutCommandeVente::A_CHARGER ? now() : null,
            ]);

            $libelleVehicule = $vehicule ? trim($vehicule->nom_vehicule.($vehicule->immatriculation ? " ({$vehicule->immatriculation})" : '')) : null;

            $this->audit->record($commande, AuditEvent::UPDATED, auth()->user(), $avant, $this->instantane($commande->fresh('vehicule')));
            CommandeVenteActiviteService::log($commande, 'mode_remise_change', [
                'mode' => $versLivraison ? 'livraison' : 'retrait',
                'vehicule' => $libelleVehicule,
            ]);
        });
    }

    /**
     * Lignes au format de saisie de la création, avec les quantités et prix figés de la précommande
     * (la préparation a pu réduire la quantité), dans l'ordre de `$commande->lignes`.
     *
     * @return list<array<string, mixed>>
     */
    private static function lignesSaisie(CommandeVente $commande): array
    {
        return $commande->lignes->map(fn (CommandeVenteLigne $l) => [
            'produit_id' => $l->variante?->produit_id,
            'variante_id' => $l->variante_id,
            'qte' => (int) ($l->quantite_preparee ?? $l->quantite_demandee),
            'prix_vente' => (float) $l->prix_vente_snapshot,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function instantane(CommandeVente $commande): array
    {
        $vehicule = $commande->vehicule_id ? $commande->loadMissing('vehicule')->vehicule : null;

        return [
            'mode_remise' => $commande->vehicule_id ? 'Livraison' : 'Retrait',
            // Même clé que l'instantané de création (commandeSnapshot) : libellée « Véhicule » par la fiche.
            'vehicule_nom' => $vehicule?->nom_vehicule,
            'statut' => $commande->statut?->label(),
        ];
    }
}
