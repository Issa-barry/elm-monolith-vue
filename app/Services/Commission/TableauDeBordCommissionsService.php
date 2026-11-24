<?php

namespace App\Services\Commission;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use App\Models\Site;
use App\Models\User;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\LecturePartsCommission;
use App\Support\Periodes\Periode;
use App\Support\Periodes\TranchesPeriode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : strictement consultatif,
 * il lit les parts de commission déjà générées et ne déclenche aucun calcul du moteur.
 *
 * Les quatre indicateurs sont la partition de CommissionKpiBuckets (même SQL que les écrans
 * Comptabilité > Commissions) : générées = en attente de validation + à payer + payées. Une part
 * appartient à la période de sa commission (`commission_enveloppes.earned_at`), comme dans les
 * listes ; les parts annulées sont exclues.
 *
 * Deux requêtes d'agrégation, quel que soit le volume : la période affichée, par jour et par type
 * de bénéficiaire (indicateurs, graphique, mini-courbes et répartition en sortent), puis le total
 * de la période de comparaison (évolutions en %).
 */
class TableauDeBordCommissionsService
{
    /** Périodes proposées : celles de Stat-Ventes, dates libres comprises. */
    public const PERIODES = Periode::TABLEAU_DE_BORD;

    private const COMPARTIMENTS = ['total_genere', 'en_attente_periode', 'payable', 'deja_paye'];

    /** Index (organization_id, earned_at) de commission_enveloppes (migration du 22/11/2026). */
    private const INDEX_PERIODE = 'comm_enveloppes_org_earned_at_idx';

    /** Types dont l'agence est celle du véhicule de l'opération, sinon celle de l'opération. */
    private const TYPES_AGENCE_DE_L_OPERATION = [
        CommissionEnveloppePart::TYPE_LIVREUR,
        CommissionEnveloppePart::TYPE_PROPRIETAIRE,
        CommissionEnveloppePart::TYPE_PARRAIN,
        CommissionEnveloppePart::TYPE_PRESTATAIRE,
    ];

    public function __construct(
        private readonly AgencePayeuseCommissionService $agencesPayeuses,
    ) {}

    /**
     * @param  list<array{value: string, label: string}>  $agences  agences proposées (agencesProposees())
     * @param  ?string  $agence  agence choisie, déjà vérifiée parmi $agences ; null = toutes les agences
     *                           du périmètre de l'utilisateur
     * @return array{
     *     periode: array{cle: string, date_debut: ?string, date_fin: ?string, options: list<array{value: string, label: string}>, libelle: string},
     *     libelle_comparaison: ?string,
     *     agences: list<array{value: string, label: string}>,
     *     agence: ?string,
     *     perimetre_limite: bool,
     *     indicateurs: array<string, array{montant: float, evolution: ?float}>,
     *     taux_paiement: ?int,
     *     evolution: array{granularite: string, tranches: list<array<string, mixed>>},
     *     repartition: list<array{type: string, label: string, montant: float}>,
     * }
     */
    public function calculer(User $user, Periode $periode, array $agences, ?string $agence = null, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $precedente = $periode->precedente($maintenant);
        $decoupage = TranchesPeriode::pour($periode);

        // Même périmètre pour la période affichée et la période de comparaison.
        $perimetres = $this->perimetres($user);
        if ($agence !== null) {
            $perimetres = $this->limiterAUneAgence($perimetres, $agence);
        }

        $totaux = array_fill_keys(self::COMPARTIMENTS, 0.0);
        $parTranche = [];
        $parType = [];

        foreach ($this->sommes($user, $perimetres, $periode, true) as $ligne) {
            // Jour de la commission (Y-m-d, quel que soit le stockage de la colonne date).
            $tranche = $decoupage->cle((string) $ligne->jour);
            foreach (self::COMPARTIMENTS as $c) {
                $totaux[$c] += (float) $ligne->{$c};
                $parTranche[$tranche][$c] = ($parTranche[$tranche][$c] ?? 0.0) + (float) $ligne->{$c};
            }
            $parType[$ligne->type] = ($parType[$ligne->type] ?? 0.0) + (float) $ligne->total_genere;
        }

        $totauxPrecedents = array_fill_keys(self::COMPARTIMENTS, 0.0);
        if ($precedente !== null) {
            foreach ($this->sommes($user, $perimetres, $precedente, false) as $ligne) {
                foreach (self::COMPARTIMENTS as $c) {
                    $totauxPrecedents[$c] += (float) $ligne->{$c};
                }
            }
        }

        $indicateurs = [];
        foreach (self::COMPARTIMENTS as $c) {
            $indicateurs[$c] = [
                'montant' => round($totaux[$c], 2),
                'evolution' => $precedente === null ? null : self::evolution($totaux[$c], $totauxPrecedents[$c]),
            ];
        }

        return [
            // Filtre unique de la page : cartes, graphique et répartition portent sur cette période.
            'periode' => [...$periode->pourFront(self::PERIODES), 'libelle' => $periode->libelle()],
            'libelle_comparaison' => $precedente?->libelle(),
            // Filtre agence : « Toutes les agences » = celles du périmètre de l'utilisateur.
            'agences' => $agences,
            'agence' => $agence,
            'perimetre_limite' => ! $user->voitToutesLesAgences(),
            'indicateurs' => $indicateurs,
            // Part du généré déjà payée, en % entier ; null sans commission sur la période.
            'taux_paiement' => round($totaux['total_genere'], 2) > 0
                ? (int) round($totaux['deja_paye'] / $totaux['total_genere'] * 100)
                : null,
            'evolution' => [
                'granularite' => $decoupage->granularite(),
                'tranches' => $this->tranches($periode, $decoupage, $parTranche, $maintenant),
            ],
            'repartition' => $this->repartition($parType),
        ];
    }

    /**
     * Sommes des compartiments sur une période — par jour de commission et type de bénéficiaire
     * pour la période affichée, en un seul total pour la période de comparaison. Une plage de dates
     * simple par requête : l'index (organization_id, earned_at) ne lit que la période.
     *
     * @param  array<string, array<int, string>|null>|null  $perimetres  null = toutes les agences
     * @return list<object>
     */
    private function sommes(User $user, ?array $perimetres, Periode $periode, bool $detail): array
    {
        $parts = CommissionEnveloppePart::query()
            ->join('commission_enveloppes as e', 'e.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->where('e.organization_id', $user->organization_id)
            ->where('commission_enveloppe_parts.statut', '!=', StatutCommission::ANNULEE->value);
        $periode->appliquer($parts, 'e.earned_at', true);

        if ($perimetres !== null) {
            $this->restreindreAuPerimetre($parts, $perimetres);
        }

        [$compartiments, $liaisons] = CommissionKpiBuckets::compartimentsSql('commission_enveloppe_parts');
        // Sur une période bornée, MySQL préfère souvent l'index (organization_id, statut) et lit
        // toute l'organisation : l'indication lui impose la plage de dates. Simple commentaire pour
        // SQLite et MariaDB.
        $indication = $periode->estBornee() ? '/*+ INDEX(e '.self::INDEX_PERIODE.') */ ' : '';
        $requete = $parts->toBase()->selectRaw($indication.$compartiments, $liaisons);

        if ($detail) {
            // Regroupement sur la date brute : les mois sont formés en PHP (quelques centaines de
            // lignes au plus), sans fonction de date propre à MySQL ou SQLite.
            $requete->addSelect('e.earned_at as jour', 'commission_enveloppe_parts.beneficiaire_type as type')
                ->groupBy('e.earned_at', 'commission_enveloppe_parts.beneficiaire_type');
        }

        return $requete->get()->all();
    }

    /**
     * Périmètre de lecture de chaque type de bénéficiaire (ADR 0061), ou null pour un utilisateur
     * qui voit toutes les agences.
     *
     * @return array<string, array<int, string>|null>|null
     */
    private function perimetres(User $user): ?array
    {
        if ($user->voitToutesLesAgences()) {
            return null;
        }

        return $this->agencesPayeuses->perimetresLecture(
            $user,
            [...self::TYPES_AGENCE_DE_L_OPERATION, CommissionEnveloppePart::TYPE_SITE],
        );
    }

    /**
     * Mêmes périmètres que les écrans Comptabilité > Commissions (ADR 0061), par type de
     * bénéficiaire : agence du véhicule de l'opération (sinon de l'opération) pour les livreurs,
     * propriétaires, parrains et consultants — CommissionSiteResponsableFilter, ici en jointures
     * (LecturePartsCommission::joindreAgence) ; le site bénéficiaire lui-même pour une commission
     * Site. Un périmètre vide ne montre rien ; les types sans écran (ex. employé) ne sont visibles
     * que d'un utilisateur qui voit toutes les agences.
     *
     * @param  array<string, array<int, string>|null>  $perimetres
     */
    private function restreindreAuPerimetre(Builder $parts, array $perimetres): void
    {
        $agence = LecturePartsCommission::joindreAgence($parts);

        // Les types qui partagent le même périmètre forment une seule condition.
        $groupes = [];
        foreach (self::TYPES_AGENCE_DE_L_OPERATION as $type) {
            $cle = $perimetres[$type] === null ? '*' : implode(',', $perimetres[$type]);
            $groupes[$cle] ??= ['perimetre' => $perimetres[$type], 'types' => []];
            $groupes[$cle]['types'][] = $type;
        }
        $perimetreSites = $perimetres[CommissionEnveloppePart::TYPE_SITE];

        $parts->where(function (Builder $visibles) use ($groupes, $perimetreSites, $agence) {
            foreach ($groupes as $groupe) {
                $visibles->orWhere(function (Builder $q) use ($groupe, $agence) {
                    $q->whereIn('commission_enveloppe_parts.beneficiaire_type', $groupe['types']);
                    if ($groupe['perimetre'] !== null) {
                        $q->whereIn(DB::raw($agence), $groupe['perimetre']);
                    }
                });
            }

            $visibles->orWhere(function (Builder $q) use ($perimetreSites) {
                $q->where('commission_enveloppe_parts.beneficiaire_type', CommissionEnveloppePart::TYPE_SITE);
                if ($perimetreSites !== null) {
                    $q->whereIn('commission_enveloppe_parts.beneficiaire_id', $perimetreSites);
                }
            });
        });
    }

    /** Évolution en % ; null sans base de comparaison (rien sur la période précédente). */
    private static function evolution(float $courant, float $precedent): ?float
    {
        if (round($precedent, 2) == 0.0) {
            return null;
        }

        return round(($courant - $precedent) / $precedent * 100, 1);
    }

    /**
     * Tranches du graphique (TranchesPeriode) avec les quatre compartiments de chacune.
     *
     * @param  array<string, array<string, float>>  $parTranche
     * @return list<array<string, mixed>>
     */
    private function tranches(Periode $periode, TranchesPeriode $decoupage, array $parTranche, CarbonImmutable $maintenant): array
    {
        return array_map(function (array $tranche) use ($parTranche) {
            foreach (self::COMPARTIMENTS as $c) {
                $tranche[$c] = round($parTranche[$tranche['cle']][$c] ?? 0.0, 2);
            }

            return $tranche;
        }, $decoupage->tranches($periode, array_map('strval', array_keys($parTranche)), $maintenant));
    }

    /**
     * Répartition du total généré par type de bénéficiaire, du plus gros au plus petit.
     *
     * @param  array<string, float>  $parType
     * @return list<array{type: string, label: string, montant: float}>
     */
    private function repartition(array $parType): array
    {
        $repartition = [];
        foreach ($parType as $type => $montant) {
            if (round($montant, 2) > 0) {
                $repartition[] = [
                    'type' => $type,
                    'label' => AgencePayeuseCommissionService::LIBELLES[$type] ?? ucfirst($type),
                    'montant' => round($montant, 2),
                ];
            }
        }
        usort($repartition, fn (array $a, array $b) => $b['montant'] <=> $a['montant']);

        return $repartition;
    }

    /**
     * Agences que l'utilisateur peut choisir dans le filtre : toutes celles de l'organisation s'il
     * voit toutes les agences, sinon les siennes (mêmes agences que
     * SiteScopeService::accessibleSiteIds()). Seule liste acceptée pour `site_id`.
     *
     * @return list<array{value: string, label: string}>
     */
    public function agencesProposees(User $user): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::query()->where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['value' => (string) $s->id, 'label' => $s->nom])->values()->all();
    }

    /**
     * Périmètres restreints à une agence choisie : pour chaque type, l'intersection de son périmètre
     * de lecture (null = toutes les agences) avec cette agence. Les types sans agence (employé) ne
     * sont plus visibles.
     *
     * @param  array<string, array<int, string>|null>|null  $perimetres
     * @return array<string, array<int, string>>
     */
    private function limiterAUneAgence(?array $perimetres, string $agence): array
    {
        $limites = [];
        foreach ([...self::TYPES_AGENCE_DE_L_OPERATION, CommissionEnveloppePart::TYPE_SITE] as $type) {
            $perimetre = $perimetres === null ? null : $perimetres[$type];
            $limites[$type] = $perimetre === null || in_array($agence, $perimetre, true) ? [$agence] : [];
        }

        return $limites;
    }
}
