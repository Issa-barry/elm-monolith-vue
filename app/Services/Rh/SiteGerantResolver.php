<?php

namespace App\Services\Rh;

use App\Enums\StatutEmploye;
use App\Models\Employe;
use App\Models\EmployeAffectation;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Unique source de vérité pour "quels employés occupaient une fonction donnée sur un site donné, à
 * une date donnée" — répond à la mission §6/§7 ("qui était gérant du site, depuis quand, jusqu'à
 * quand, actif") et sera consommée en Phase 2 par CommissionGroupeSyncService::syncEquipeDepotSite().
 *
 * `$fonctionRhId` n'est JAMAIS deviné par libellé/code ("Gérant de dépôt", "GDE"...) : chaque
 * organisation nomme sa fonction comme elle veut (décision finale du 2026-08-21) — c'est à
 * l'appelant de résoudre quel fonction_rh_id représente un gérant pour SON organisation (Phase 2
 * introduira le marqueur de configuration correspondant, cf. plan).
 */
class SiteGerantResolver
{
    /** @return Collection<int, Employe> */
    public function occupantsActifsA(Site $site, string $fonctionRhId, CarbonInterface $date): Collection
    {
        $employeIds = EmployeAffectation::query()
            ->where('site_id', $site->id)
            ->where('fonction_rh_id', $fonctionRhId)
            ->activeA($date)
            ->pluck('employe_id');

        if ($employeIds->isEmpty()) {
            return Employe::query()->whereRaw('1 = 0')->get();
        }

        return Employe::whereIn('id', $employeIds)
            ->where('statut', StatutEmploye::ACTIF->value)
            ->get();
    }

    /**
     * Historique complet des occupants d'une fonction sur un site (toutes périodes, actives et
     * closes) — utilisé pour afficher "qui a été gérant de ce site, depuis quand, jusqu'à quand".
     */
    public function historique(Site $site, string $fonctionRhId): Collection
    {
        return EmployeAffectation::query()
            ->where('site_id', $site->id)
            ->where('fonction_rh_id', $fonctionRhId)
            ->with('employe.personne')
            ->orderByDesc('debut_at')
            ->get();
    }
}
