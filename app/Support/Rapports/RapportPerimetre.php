<?php

namespace App\Support\Rapports;

use App\Support\Vehicules\SituationPeriode;
use Carbon\CarbonImmutable;

/**
 * Périmètre d'un rapport d'activité, toujours résolu côté serveur (RapportPerimetreResolver) :
 * jamais construit à partir de paramètres HTTP non vérifiés.
 *
 * - `siteIds` null  = toutes les agences de l'organisation ; [] = aucune agence accessible (rien).
 * - `agentId` null  = tous les agents du périmètre ; sinon un seul agent (imposé pour « Ma situation »).
 */
final class RapportPerimetre
{
    /**
     * @param  list<string>|null  $siteIds
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly ?array $siteIds,
        public readonly ?string $agentId,
        public readonly SituationPeriode $periode,
        public readonly bool $maSituation,
    ) {}

    public function debut(): CarbonImmutable
    {
        return $this->periode->debut ?? CarbonImmutable::now()->startOfDay();
    }

    public function fin(): CarbonImmutable
    {
        return $this->periode->fin ?? CarbonImmutable::now()->endOfDay();
    }

    public function aucuneAgence(): bool
    {
        return $this->siteIds === [];
    }
}
