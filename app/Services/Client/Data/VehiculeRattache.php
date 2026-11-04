<?php

namespace App\Services\Client\Data;

use App\Enums\ActiviteCompte;
use App\Models\Vehicule;

/**
 * Un véhicule rattaché à un compte et la nature de chacun de ses liens (ADR 0054). Les droits de
 * lecture dépendent du lien : un véhicule seulement parrainé n'ouvre ni l'équipe, ni les ventes, ni
 * les dépenses, ni les coordonnées du propriétaire.
 */
final class VehiculeRattache
{
    /**
     * @param  list<ActiviteCompte>  $liens
     */
    public function __construct(
        public readonly Vehicule $vehicule,
        public readonly array $liens,
    ) {}

    public function a(ActiviteCompte $lien): bool
    {
        return in_array($lien, $this->liens, true);
    }

    /** Propriétaire ou livreur : les droits de consultation complets d'avant l'ADR 0054. */
    public function accesOperationnel(): bool
    {
        return $this->a(ActiviteCompte::PROPRIETAIRE) || $this->a(ActiviteCompte::LIVREUR);
    }

    /** @return string[] */
    public function liensValeurs(): array
    {
        return array_map(fn (ActiviteCompte $lien) => $lien->value, $this->liens);
    }
}
