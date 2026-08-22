<?php

namespace App\Services\Tresorerie;

use App\Models\Site;
use App\Services\Tresorerie\Obligations\LivreurObligationContributor;
use App\Services\Tresorerie\Obligations\ObligationAccumulator;
use App\Services\Tresorerie\Obligations\ObligationContributor;
use App\Services\Tresorerie\Obligations\ProprietaireObligationContributor;
use App\Services\Tresorerie\Obligations\SalaireObligationContributor;

/**
 * Calcule, pour un mois donné, les obligations RESTANTES (montant déjà payé
 * déduit, jamais le brut théorique) de chaque agence : commissions livreurs
 * (P1/P2), propriétaires (mensuel) et salaires (mensuel) — cf. anciennement
 * BesoinTresorerieService, renommé/refactoré pour le chantier Financement des
 * agences.
 *
 * Extensible par construction : un nouveau type de commission (Site,
 * Consultant...) s'ajoute en implémentant ObligationContributor et en
 * l'enregistrant dans self::contributors(), jamais par une condition
 * supplémentaire ici. Un contributeur ne doit exister que si le métier a
 * explicitement défini quelle agence est responsable de son paiement — cf.
 * docblock de l'interface.
 *
 * Chaque poste porte aussi un champ "{colonne}_du" : le montant théorique
 * total (avant paiement), qui sert uniquement à distinguer côté écran "rien à
 * payer ce mois" (du = 0) de "déjà réglé" (du > 0, reste = 0) — jamais une
 * base de calcul du montant à financer.
 */
class ObligationsAgenceService
{
    /** @var list<ObligationContributor> */
    private array $contributors;

    public function __construct(
        LivreurObligationContributor $livreur,
        ProprietaireObligationContributor $proprietaire,
        SalaireObligationContributor $salaire,
    ) {
        $this->contributors = [$livreur, $proprietaire, $salaire];
    }

    /** @return list<array<string, mixed>> */
    public function calculerPourMois(string $organizationId, int $annee, int $mois): array
    {
        $besoin = [];

        foreach ($this->contributors as $contributor) {
            $contributor->collecter($organizationId, $annee, $mois, $besoin);
        }

        return $this->formatResultat($organizationId, $besoin);
    }

    /**
     * @param  list<array<string, float|string|null>>  $rows
     * @return array<string, float>
     */
    public function totalGeneral(array $rows): array
    {
        $champs = [];
        foreach ($this->colonnes() as $colonne) {
            $champs[] = $colonne;
            $champs[] = "{$colonne}_du";
        }
        $champs[] = 'total';
        $champs[] = 'total_du';

        return collect($champs)
            ->mapWithKeys(fn (string $champ) => [$champ => round((float) array_sum(array_column($rows, $champ)), 2)])
            ->all();
    }

    /**
     * Détail des bénéficiaires composant le besoin d'une agence pour un mois
     * (drill-down) — $siteId à null pour le regroupement "Sans agence".
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function detailAgence(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        $detail = [];
        foreach ($this->contributors as $contributor) {
            $detail = [...$detail, ...$contributor->detail($organizationId, $annee, $mois, $siteId)];
        }

        return $detail;
    }

    /** @return list<string> */
    public function colonnes(): array
    {
        return array_merge(...array_map(fn (ObligationContributor $c) => $c->colonnes(), $this->contributors));
    }

    /** @return array<string, 'quinzaine_1'|'fin_de_mois'> */
    public function echeancesParColonne(): array
    {
        return array_merge(...array_map(fn (ObligationContributor $c) => $c->echeancesParColonne(), $this->contributors));
    }

    /**
     * @param  array<string, array<string, float>>  $besoin
     * @return list<array<string, mixed>>
     */
    private function formatResultat(string $organizationId, array $besoin): array
    {
        $rows = Site::where('organization_id', $organizationId)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Site $site) => $this->buildRow($site->id, $site->nom, $besoin[$site->id] ?? []))
            ->all();

        if (isset($besoin[ObligationAccumulator::SANS_AGENCE])) {
            $sansAgence = $this->buildRow(null, 'Sans agence', $besoin[ObligationAccumulator::SANS_AGENCE]);
            if ($sansAgence['total'] > 0.0) {
                $rows[] = $sansAgence;
            }
        }

        return $rows;
    }

    /** @param  array<string, float>  $montants */
    private function buildRow(?string $siteId, string $siteNom, array $montants): array
    {
        $row = ['site_id' => $siteId, 'site_nom' => $siteNom];
        $total = 0.0;
        $totalDu = 0.0;

        foreach ($this->colonnes() as $colonne) {
            $valeur = round($montants[$colonne] ?? 0.0, 2);
            $valeurDu = round($montants["{$colonne}_du"] ?? 0.0, 2);
            $row[$colonne] = $valeur;
            $row["{$colonne}_du"] = $valeurDu;
            $total += $valeur;
            $totalDu += $valeurDu;
        }

        $row['total'] = round($total, 2);
        $row['total_du'] = round($totalDu, 2);

        return $row;
    }
}
