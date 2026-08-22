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
 * une date donnée" — outil RH générique, indépendant de toute commission (la commission est
 * désormais attribuée directement au site métier de l'opération, jamais à un employé/gérant, cf.
 * décision produit 2026-08-21).
 *
 * `$fonctionRhId` n'est JAMAIS deviné par libellé/code ("Gérant de dépôt", "GDE"...) : chaque
 * organisation nomme sa fonction comme elle veut — c'est à l'appelant de résoudre quel
 * fonction_rh_id l'intéresse pour SON organisation.
 */
class SiteGerantResolver
{
    /** @return Collection<int, Employe> */
    public function occupantsActifsA(Site $site, string $fonctionRhId, CarbonInterface $date): Collection
    {
        return $this->occupantsActifsPourFonctions($site, [$fonctionRhId], $date);
    }

    /**
     * Variante multi-fonctions : interroge plusieurs fonction_rh_id à la fois (ex: reporting sur
     * un ensemble de fonctions apparentées) — jamais une seule fonction supposée par convention.
     *
     * @param  array<int, string>  $fonctionRhIds
     * @return Collection<int, Employe>
     */
    public function occupantsActifsPourFonctions(Site $site, array $fonctionRhIds, CarbonInterface $date): Collection
    {
        if (empty($fonctionRhIds)) {
            return Employe::query()->whereRaw('1 = 0')->get();
        }

        $employeIds = EmployeAffectation::query()
            ->where('site_id', $site->id)
            ->whereIn('fonction_rh_id', $fonctionRhIds)
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
