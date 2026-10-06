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

    /**
     * « Toute la période » (fiche agent seulement : le rapport et « Ma situation » ne la proposent
     * pas) n'a pas de bornes : on couvre tout l'historique, y compris une date d'encaissement
     * saisie dans le futur. Toute autre période sans bornes retombe sur aujourd'hui.
     */
    public function debut(): CarbonImmutable
    {
        if ($this->toutePeriode()) {
            return CarbonImmutable::create(1970, 1, 1)->startOfDay();
        }

        return $this->periode->debut ?? CarbonImmutable::now()->startOfDay();
    }

    public function fin(): CarbonImmutable
    {
        if ($this->toutePeriode()) {
            return CarbonImmutable::create(9999, 12, 31)->endOfDay();
        }

        return $this->periode->fin ?? CarbonImmutable::now()->endOfDay();
    }

    private function toutePeriode(): bool
    {
        return $this->periode->cle === SituationPeriode::TOUT && $this->periode->debut === null;
    }

    public function aucuneAgence(): bool
    {
        return $this->siteIds === [];
    }
}
