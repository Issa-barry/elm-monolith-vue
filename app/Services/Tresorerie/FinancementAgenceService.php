<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Enums\StatutFinancementAgence;
use App\Models\CompteTresorerie;
use App\Services\PeriodePaiementService;
use App\Services\Tresorerie\Obligations\ObligationAccumulator;
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
 *
 * Position complète de l'agence (ADR 0016) — un seul calcul pour le besoin ET la remise :
 *
 *   disponible propre   = disponible − fonds d'autres agences présents dans ses supports
 *   à conserver         = total à régler sur l'échéance + obligations échues impayées
 *   à financer          = max(0, à conserver − disponible propre − fonds en transit)
 *   excédent à remettre = max(0, disponible propre − à conserver)
 *   remise obligatoire  = fonds d'autres agences pas encore engagés dans un règlement
 *   total à remettre    = remise obligatoire + excédent à remettre
 *
 * Les fonds d'autres agences ne paient jamais les obligations de l'agence qui les détient : ils
 * sont remis en totalité. La trésorerie principale (`is_central_tresorerie`, ADR 0017) ne se remet
 * rien et ne se finance pas elle-même : sa ligne ne porte que ses obligations et son disponible.
 * Lecture seule : aucun mouvement, aucune écriture.
 */
class FinancementAgenceService
{
    public function __construct(
        private readonly ObligationsAgenceService $obligations,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly DetteInterAgencesService $dettes,
        private readonly SiteCentralTresorerieResolver $siteCentral,
    ) {}

    /** @return list<array<string, mixed>> */
    public function calculerPourEcheance(string $organizationId, int $annee, int $mois, string $echeance): array
    {
        $obligationRows = $this->obligations->calculerPourMois($organizationId, $annee, $mois);
        [$debut, $fin] = $this->dateRangePourEcheance($annee, $mois, $echeance);
        $bucketsFiables = $this->bucketsPourEcheance($echeance);
        $contexte = [
            'central' => $this->siteCentral->centralOuNull($organizationId)?->id,
            'arrieres' => $this->obligations->arrieresParSite($organizationId, Carbon::create($annee, $mois, 1)->startOfDay()),
            // Échéance de fin de mois : la 1re quinzaine du même mois est échue, ses restants sont conservés.
            'buckets_echus' => $echeance === 'p2' ? ['quinzaine_1'] : [],
            'fonds_tiers' => $this->fondsAutresAgencesParSite($organizationId, $fin),
            'caisses_agents' => $this->soldeCaissesAgentsParSite($organizationId, $fin),
        ];

        return array_map(
            fn (array $row) => $this->enrichirLigne($organizationId, $row, $bucketsFiables, $debut, $fin, $contexte),
            $obligationRows,
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function totalGeneral(array $rows): array
    {
        $champs = [
            'total_a_regler', 'arrieres', 'a_conserver', 'disponible', 'fonds_autres_agences', 'disponible_propre',
            'fonds_en_transit', 'deja_finance', 'a_financer', 'remise_obligatoire', 'excedent_a_remettre', 'total_a_remettre',
        ];

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

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $contexte
     */
    private function enrichirLigne(string $organizationId, array $row, array $bucketsFiables, Carbon $debut, Carbon $fin, array $contexte): array
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

        // Obligations échues encore impayées : mois antérieurs, et 1re quinzaine du mois vue depuis la fin de mois.
        $arrieres = (float) ($contexte['arrieres'][$siteId ?? ObligationAccumulator::SANS_AGENCE] ?? 0.0);
        foreach ($echeancesParColonne as $colonne => $bucket) {
            if (in_array($bucket, $contexte['buckets_echus'], true)) {
                $arrieres += (float) ($row[$colonne] ?? 0.0);
            }
        }
        $arrieres = round($arrieres, 2);
        $aConserver = round($totalARegler + $arrieres, 2);
        $estCentrale = $siteId !== null && $siteId === $contexte['central'];

        $positionVide = [
            'disponible' => null,
            'fonds_autres_agences' => null,
            'disponible_propre' => null,
            'fonds_en_transit' => null,
            'deja_finance' => null,
            'a_financer' => null,
            'remise_obligatoire' => null,
            'excedent_a_remettre' => null,
            'total_a_remettre' => null,
        ];

        if ($siteId === null || ! $this->positionFiable($organizationId, $siteId)) {
            return [
                ...$row,
                ...$positionVide,
                'total_a_regler' => $totalARegler,
                'arrieres' => $arrieres,
                'a_conserver' => $aConserver,
                'est_tresorerie_principale' => $estCentrale,
                'statut' => StatutFinancementAgence::DONNEES_INCOMPLETES->value,
            ];
        }

        $disponible = $this->disponibilite->disponiblePourSite($organizationId, $siteId, $fin);

        if ($estCentrale) {
            return [
                ...$row,
                ...$positionVide,
                'total_a_regler' => $totalARegler,
                'arrieres' => $arrieres,
                'a_conserver' => $aConserver,
                'disponible' => $disponible,
                'est_tresorerie_principale' => true,
                'statut' => StatutFinancementAgence::TRESORERIE_PRINCIPALE->value,
            ];
        }

        $tiers = $contexte['fonds_tiers'][$siteId] ?? ['especes' => 0.0, 'autres' => 0.0, 'a_verser' => 0.0];
        // Espèces dues encore chez les agents : hors du disponible (caisses dédiées exclues), donc
        // jamais déduites une deuxième fois — elles sont réputées rester d'abord chez les agents.
        $especesEnAgence = max(0.0, $tiers['especes'] - (float) ($contexte['caisses_agents'][$siteId] ?? 0.0));
        $fondsTiers = round($tiers['autres'] + $especesEnAgence, 2);
        $disponiblePropre = round($disponible - $fondsTiers, 2);

        $enTransit = $this->disponibilite->fondsEnTransitVersSite($organizationId, $siteId, $debut, $fin);
        $dejaFinance = $this->disponibilite->dejaFinancePourSite($organizationId, $siteId, $debut, $fin);
        // Le transit affecté à CETTE échéance est déduit du besoin — sinon la trésorerie principale
        // pourrait renvoyer un deuxième financement alors qu'un premier est déjà en route pour le
        // même besoin (revue Codex du 2026-08-22, double financement).
        $aFinancer = round(max(0.0, $aConserver - $disponiblePropre - $enTransit), 2);
        $remiseObligatoire = round($tiers['a_verser'], 2);
        $excedent = round(max(0.0, $disponiblePropre - $aConserver), 2);
        $totalARemettre = round($remiseObligatoire + $excedent, 2);

        $statut = match (true) {
            $aFinancer > 0.0 && $enTransit > 0.0 => StatutFinancementAgence::FONDS_EN_TRANSIT,
            $aFinancer > 0.0 => StatutFinancementAgence::A_FINANCER,
            $totalARemettre > 0.0 => StatutFinancementAgence::A_REMETTRE,
            default => StatutFinancementAgence::COUVERT,
        };

        return [
            ...$row,
            'total_a_regler' => $totalARegler,
            'arrieres' => $arrieres,
            'a_conserver' => $aConserver,
            'disponible' => $disponible,
            'fonds_autres_agences' => $fondsTiers,
            'disponible_propre' => $disponiblePropre,
            'fonds_en_transit' => $enTransit,
            'deja_finance' => $dejaFinance,
            'a_financer' => $aFinancer,
            'remise_obligatoire' => $remiseObligatoire,
            'excedent_a_remettre' => $excedent,
            'total_a_remettre' => $totalARemettre,
            'est_tresorerie_principale' => false,
            'statut' => $statut->value,
        ];
    }

    /**
     * Fonds encaissés pour d'autres agences et encore détenus, par agence qui les détient (ADR
     * 0012) : à verser ou réservés dans un règlement en brouillon (l'argent n'est pas encore
     * parti), séparés espèces / autres moyens ; `a_verser` = la part pas encore engagée dans un
     * règlement, c'est-à-dire la remise obligatoire. Encaissements postérieurs à $fin ignorés.
     *
     * @return array<string, array{especes: float, autres: float, a_verser: float}>
     */
    private function fondsAutresAgencesParSite(string $organizationId, Carbon $fin): array
    {
        $parSite = [];

        foreach ($this->dettes->lignes($organizationId) as $ligne) {
            if (! in_array($ligne['statut'], [DetteInterAgencesService::A_VERSER, DetteInterAgencesService::RESERVE], true)) {
                continue;
            }
            if ($ligne['date_encaissement'] !== null && $ligne['date_encaissement'] > $fin->toDateString()) {
                continue;
            }

            $site = $ligne['site_debiteur_id'];
            $parSite[$site] ??= ['especes' => 0.0, 'autres' => 0.0, 'a_verser' => 0.0];
            $parSite[$site][$ligne['mode_paiement'] === ModePaiement::ESPECES->value ? 'especes' : 'autres'] += $ligne['montant'];
            if ($ligne['statut'] === DetteInterAgencesService::A_VERSER) {
                $parSite[$site]['a_verser'] += $ligne['montant'];
            }
        }

        return $parSite;
    }

    /** @return array<string, float> solde des caisses dédiées aux agents, par agence */
    private function soldeCaissesAgentsParSite(string $organizationId, Carbon $fin): array
    {
        return $this->disponibilite->situationParSupport($organizationId, $fin)
            ->filter(fn (array $support) => $support['agent_id'] !== null)
            ->groupBy('site_id')
            ->map(fn ($supports) => max(0.0, (float) $supports->sum('solde')))
            ->all();
    }

    /**
     * Un site n'a une position de trésorerie fiable que s'il a au moins un
     * support de trésorerie actif ET que TOUS ces supports ont un solde
     * d'ouverture validé — un seul support non initialisé suffit à rendre le
     * "disponible" du site incomplet (ex: caisse initialisée mais wallet
     * Mobile Money oublié), cf. règle #7 de la spec : jamais un faux besoin
     * précis. Avant cette correction (revue Codex du 2026-08-22), un seul
     * support validé sur plusieurs suffisait à tort à déclarer le site fiable.
     *
     * Seuls les supports d'agence comptent : une caisse dédiée à un agent
     * démarre à 0 sans solde d'ouverture et n'entre pas dans le disponible
     * (cf. TresorerieDisponibiliteService::disponiblePourSite()), elle ne doit
     * donc jamais rendre la position du site « non fiable ».
     */
    private function positionFiable(string $organizationId, string $siteId): bool
    {
        $comptes = CompteTresorerie::forOrg($organizationId)->where('site_id', $siteId)->actifs()->agence()->with('soldeOuverture')->get();

        if ($comptes->isEmpty()) {
            return false;
        }

        return $comptes->every(fn (CompteTresorerie $c) => $c->soldeOuverture?->isValide() === true);
    }
}
