<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de `Api\Client\LivraisonsEnCoursController` (commande de vente ou
 * transfert logistique en transit — même forme pour les deux, cf.
 * `formatCommande()`/`formatTransfert()`).
 */
final class LivraisonEnCoursRow implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly string $statut,
        public readonly string $statutLabel,
        public readonly string $siteSource,
        public readonly string $siteDestination,
        public readonly ?LivraisonEnCoursVehicule $vehicule,
        public readonly string $equipeNom,
        public readonly ?string $dateDepart,
        public readonly ?string $dateArriveePrevue,
        public readonly int $nbPacks,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'statut' => $this->statut,
            'statut_label' => $this->statutLabel,
            'site_source' => $this->siteSource,
            'site_destination' => $this->siteDestination,
            'vehicule' => $this->vehicule,
            'equipe_nom' => $this->equipeNom,
            'date_depart' => $this->dateDepart,
            'date_arrivee_prevue' => $this->dateArriveePrevue,
            'nb_packs' => $this->nbPacks,
        ];
    }
}
