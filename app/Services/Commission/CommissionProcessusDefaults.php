<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Models\CommissionProcessus;
use App\Models\Parametre;
use App\Models\Vehicule;
use InvalidArgumentException;

/**
 * Valeurs par défaut d'un CommissionProcessus par code — factorise le mapping libellé/déclencheur/
 * stratégie d'ancrage jusqu'ici dupliqué entre CommissionEnveloppeGenerator, EquipeLivraisonController
 * et Settings\CommissionRegleController. Aucune notion d'« activation » séparée : toute organisation
 * utilise le même moteur dès sa création — le processus est provisionné à la volée s'il n'existe pas
 * encore, jamais un pré-requis silencieusement bloquant (cf. CommissionEnveloppeGenerator::executerAvecTentative()).
 */
class CommissionProcessusDefaults
{
    /** @return array{libelle:string, declencheur:string, strategie_ancrage_site:string} */
    public static function pour(string $organizationId, string $code): array
    {
        return match ($code) {
            CommissionProcessus::CODE_VENTE => [
                'libelle' => 'Vente',
                'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            ],
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT => [
                'libelle' => 'Distribution client',
                'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            ],
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT => [
                'libelle' => 'Transfert logistique',
                'declencheur' => Parametre::getDeclencheurCommissionLogistique($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::SOURCE->value,
            ],
            default => throw new InvalidArgumentException("Code processus inconnu : {$code}"),
        };
    }

    public static function resoudreOuCreer(string $organizationId, string $code): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $organizationId, 'code' => $code],
            [...self::pour($organizationId, $code), 'statut' => CommissionActivationStatut::ACTIF->value],
        );
    }

    /**
     * Colonne d'usage (`Vehicule::livraison_vente`/`livraison_logistique`) qui rend CE processus
     * applicable à un véhicule — source unique du mapping "processus disponible" ≠ "processus
     * obligatoire" (révisé le 31/08/2026, incident : un véhicule Vente-only affichait Distribution
     * client/Transfert logistique comme « à faire » alors qu'aucune donnée métier ne l'autorise à
     * exercer ces processus). Consommée par VehiculeController (onglets/tabs et statuts de partage
     * de la fiche véhicule) et EquipeLivraisonController (validation `processus_code`), pour que
     * les deux ne puissent jamais diverger sur "ce processus a-t-il un sens pour ce véhicule ?".
     * `vente` ↔ livraison_vente ; `distribution_client`/`logistique_transfert` ↔ livraison_logistique
     * (une distribution client comme un transfert interne sont tous deux des opérations
     * logistiques, jamais des ventes au comptoir).
     */
    public static function usageVehiculeRequis(string $code): string
    {
        return match ($code) {
            CommissionProcessus::CODE_VENTE => 'livraison_vente',
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT,
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT => 'livraison_logistique',
            default => throw new InvalidArgumentException("Code processus inconnu : {$code}"),
        };
    }

    public static function estApplicablePourVehicule(string $code, Vehicule $vehicule): bool
    {
        return (bool) $vehicule->{self::usageVehiculeRequis($code)};
    }

    /**
     * Sous-ensemble de `CommissionRegleController::processusCodesDisponibles()` réellement
     * applicable à ce véhicule selon ses usages — jamais l'inverse (aucun ordre ajouté, aucun code
     * inventé). Un véhicule sans aucun usage actif (`is_utilisable() === false`, cas transitoire)
     * renvoie un tableau vide : à l'appelant de décider du repli (cf. VehiculeController::show()).
     *
     * @param  array<int, string>  $codesDisponibles
     * @return array<int, string>
     */
    public static function codesApplicablesPourVehicule(Vehicule $vehicule, array $codesDisponibles): array
    {
        return array_values(array_filter(
            $codesDisponibles,
            fn (string $code) => self::estApplicablePourVehicule($code, $vehicule),
        ));
    }
}
