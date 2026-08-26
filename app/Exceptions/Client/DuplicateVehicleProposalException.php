<?php

namespace App\Exceptions\Client;

use RuntimeException;

/**
 * Une proposition en attente existe déjà pour cette immatriculation — pas une
 * erreur serveur, chaque surface (Inertia, API) la traduit dans son propre
 * format d'erreur (redirect + withErrors, ou 422 JSON).
 */
class DuplicateVehicleProposalException extends RuntimeException
{
    public function __construct(public readonly string $immatriculation)
    {
        parent::__construct("Une proposition en attente existe déjà pour l'immatriculation {$immatriculation}.");
    }
}
