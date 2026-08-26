<?php

namespace App\Services\Tresorerie;

use App\Enums\StatutFinancementAgence;
use App\Models\CompteTresorerie;
use App\Services\PeriodePaiementService;
use Carbon\Carbon;

/**
 * Remplace l'ancien "Total à envoyer" (obligations restantes uniquement, cf.
 * ObligationsAgenceService) par un vrai calcul de financement :
 *
 *   à_financer = max(0, total_a_regler_sur_l_echeance - disponible)
 *
 * où `disponible` vient du grand livre (TresorerieDisponibiliteService, donc
 * solde d'ouverture + encaissements + financements reçus - paiements locaux -
 * remises envoyées au siège, cf. spec du chantier) et JAMAIS des fonds encore
 * en transit (affichés à part). Si un site n'a aucun support de trésorerie
 * configuré, ou aucun solde d'ouverture validé sur au moins un de ses
 * supports, le calcul de disponibilité n'est pas fiable : la ligne est
 * marquée DONNEES_INCOMPLETES plutôt que d'afficher un faux montant précis.
 */
class FinancementAgenceService
{
    public function __construct(
        private readonly ObligationsAgenceService $obligations,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /** @return list<array<string, mixed>> */
    public function calculerPourEcheance(string $organizationId, int $annee, int $mois, string $echeance): array
    {
        $obligationRows = $this->obligations->calculerPourMois($organizationId, $annee, $mois);
        [$debut, $fin] = $this->dateRangePourEcheance($annee, $mois, $echeance);
        $bucketsFiables = $this->bucketsPourEcheance($echeance);

        return array_map(
            fn (array $row) => $this->enrichirLigne($organizationId, $row, $bucketsFiables, $debut, $fin),
            $obligationRows,
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function totalGeneral(array $rows): array
    {
        $champs = ['total_a_regler', 'disponible', 'fonds_en_transit', 'deja_finance', 'a_financer'];

        return collect($champs)
            ->mapWithKeys(fn (string $champ) => [$champ => round((float) array_sum(array_column($rows, $champ)), 2)])
            ->all();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function dateRangePourEcheance(int $annee, int $mois, string $echeance): array
    {
        return match ($echeance) {
            'p1' => PeriodePaiementService::dateRangeFor($annee, $mois, PeriodePaiementService::P1),
            'p2' => PeriodePaiementService::dateRangeFor($annee, $mois, PeriodePaiementService::P2),
            default => [Carbon::create($annee, $mois, 1)->startOfDay(), Carbon::create($annee, $mois)->endOfMonth()->endOfDay()],
        };
    }

    /** @return list<'quinzaine_1'|'fin_de_mois'> */
    private function bucketsPourEcheance(string $echeance): array
    {
        return match ($echeance) {
            'p1' => ['quinzaine_1'],
            'p2' => ['fin_de_mois'],
            default => ['quinzaine_1', 'fin_de_mois'],
        };
    }

    /** @param  array<string, mixed>  $row */
    private function enrichirLigne(string $organizationId, array $row, array $bucketsFiables, Carbon $debut, Carbon $fin): array
    {
        $echeancesParColonne = $this->obligations->echeancesParColonne();

        $totalARegler = 0.0;
        foreach ($echeancesParColonne as $colonne => $bucket) {
            if (in_array($bucket, $bucketsFiables, true)) {
                $totalARegler += (float) ($row[$colonne] ?? 0.0);
            }
        }
        $totalARegler = round($totalARegler, 2);

        $siteId = $row['site_id'];

        if ($siteId === null || ! $this->positionFiable($organizationId, $siteId)) {
            return [
                ...$row,
                'total_a_regler' => $totalARegler,
                'disponible' => null,
                'fonds_en_transit' => null,
                'deja_finance' => null,
                'a_financer' => null,
                'statut' => StatutFinancementAgence::DONNEES_INCOMPLETES->value,
            ];
        }

        $disponible = $this->disponibilite->disponiblePourSite($organizationId, $siteId, $fin);
        $enTransit = $this->disponibilite->fondsEnTransitVersSite($organizationId, $siteId, $debut, $fin);
        $dejaFinance = $this->disponibilite->dejaFinancePourSite($organizationId, $siteId, $debut, $fin);
        // Le transit affecté à CETTE échéance est déduit du besoin — sinon le siège
        // pourrait renvoyer un deuxième financement alors qu'un premier est déjà en
        // route pour le même besoin (revue Codex du 2026-08-22, double financement).
        $aFinancer = round(max(0.0, $totalARegler - $disponible - $enTransit), 2);

        $statut = match (true) {
            $totalARegler <= 0.0 || $aFinancer <= 0.0 => StatutFinancementAgence::COUVERT,
            $enTransit > 0.0 => StatutFinancementAgence::FONDS_EN_TRANSIT,
            default => StatutFinancementAgence::A_FINANCER,
        };

        return [
            ...$row,
            'total_a_regler' => $totalARegler,
            'disponible' => $disponible,
            'fonds_en_transit' => $enTransit,
            'deja_finance' => $dejaFinance,
            'a_financer' => $aFinancer,
            'statut' => $statut->value,
        ];
    }

    /**
     * Un site n'a une position de trésorerie fiable que s'il a au moins un
     * support de trésorerie actif ET que TOUS ces supports ont un solde
     * d'ouverture validé — un seul support non initialisé suffit à rendre le
     * "disponible" du site incomplet (ex: caisse initialisée mais wallet
     * Mobile Money oublié), cf. règle #7 de la spec : jamais un faux besoin
     * précis. Avant cette correction (revue Codex du 2026-08-22), un seul
     * support validé sur plusieurs suffisait à tort à déclarer le site fiable.
     */
    private function positionFiable(string $organizationId, string $siteId): bool
    {
        $comptes = CompteTresorerie::forOrg($organizationId)->where('site_id', $siteId)->actifs()->with('soldeOuverture')->get();

        if ($comptes->isEmpty()) {
            return false;
        }

        return $comptes->every(fn (CompteTresorerie $c) => $c->soldeOuverture?->isValide() === true);
    }
}
