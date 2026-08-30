<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Models\CommissionProcessus;
use App\Models\Parametre;
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
}
