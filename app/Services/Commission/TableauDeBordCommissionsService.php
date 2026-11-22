<?php

namespace App\Services\Commission;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use App\Models\User;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\LecturePartsCommission;
use App\Support\Periodes\Periode;
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
    /** Périodes proposées : celles du tableau de bord des ventes (sans dates libres). */
    public const PERIODES = [...Periode::RAPIDES, ...Periode::TRIMESTRES_SEMESTRES, Periode::TOUT];

    /** Au-delà de ce nombre de jours, l'évolution est présentée mois par mois. */
    private const JOURS_MAX_PAR_JOUR = 31;

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

    private const MOIS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private readonly AgencePayeuseCommissionService $agencesPayeuses,
    ) {}

    /**
     * @return array{
     *     periode: string,
     *     periodes: list<array{value: string, label: string}>,
     *     libelle_periode: string,
     *     libelle_comparaison: ?string,
     *     agences: list<string>|null,
     *     indicateurs: array<string, array{montant: float, evolution: ?float}>,
     *     taux_paiement: ?int,
     *     evolution: array{granularite: string, tranches: list<array<string, mixed>>},
     *     repartition: list<array{type: string, label: string, montant: float}>,
     * }
     */
    public function calculer(User $user, Periode $periode, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $precedente = $periode->precedente($maintenant);
        $parMois = ! $periode->estBornee()
            || $periode->debut->diffInDays($periode->fin->startOfDay()) + 1 > self::JOURS_MAX_PAR_JOUR;

        $perimetres = $this->perimetres($user);

        $totaux = array_fill_keys(self::COMPARTIMENTS, 0.0);
        $parTranche = [];
        $parType = [];

        foreach ($this->sommes($user, $perimetres, $periode, true) as $ligne) {
            // Jour de la commission (Y-m-d, quel que soit le stockage de la colonne date).
            $tranche = substr((string) $ligne->jour, 0, $parMois ? 7 : 10);
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
            'periode' => $periode->cle,
            'periodes' => Periode::options(self::PERIODES),
            'libelle_periode' => $periode->libelle(),
            'libelle_comparaison' => $precedente?->libelle(),
            'agences' => $this->agencesVisibles($user),
            'indicateurs' => $indicateurs,
            // Part du généré déjà payée, en % entier ; null sans commission sur la période.
            'taux_paiement' => round($totaux['total_genere'], 2) > 0
                ? (int) round($totaux['deja_paye'] / $totaux['total_genere'] * 100)
                : null,
            'evolution' => [
                'granularite' => $parMois ? 'mois' : 'jour',
                'tranches' => $this->tranches($periode, $parTranche, $parMois, $maintenant),
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
     * Tranches du graphique, dans l'ordre, sans trou : chaque jour (ou mois) de la période jusqu'à
     * aujourd'hui ; pour « Toutes les périodes », du premier au dernier mois qui a des commissions.
     *
     * @param  array<string, array<string, float>>  $parTranche
     * @return list<array<string, mixed>>
     */
    private function tranches(Periode $periode, array $parTranche, bool $parMois, CarbonImmutable $maintenant): array
    {
        if ($periode->estBornee()) {
            $debut = $periode->debut;
            $fin = $periode->fin->lessThan($maintenant) ? $periode->fin : $maintenant;
        } elseif ($parTranche !== []) {
            ksort($parTranche);
            $debut = CarbonImmutable::parse(array_key_first($parTranche).'-01');
            $fin = CarbonImmutable::parse(array_key_last($parTranche).'-01');
        } else {
            return [];
        }

        $cles = [];
        if ($parMois) {
            for ($mois = $debut->day(1)->startOfDay(); $mois->lessThanOrEqualTo($fin); $mois = $mois->addMonthNoOverflow()) {
                $cles[] = $mois;
            }
        } else {
            for ($jour = $debut->startOfDay(); $jour->lessThanOrEqualTo($fin); $jour = $jour->addDay()) {
                $cles[] = $jour;
            }
        }

        $plusieursAnnees = $cles !== [] && $cles[0]->year !== end($cles)->year;

        return array_map(function (CarbonImmutable $date) use ($parTranche, $parMois, $plusieursAnnees) {
            $cle = $date->format($parMois ? 'Y-m' : 'Y-m-d');
            $label = $parMois
                ? self::MOIS[$date->month - 1].($plusieursAnnees ? ' '.$date->format('y') : '')
                : $date->format('d/m');

            $tranche = ['cle' => $cle, 'label' => $label];
            foreach (self::COMPARTIMENTS as $c) {
                $tranche[$c] = round($parTranche[$cle][$c] ?? 0.0, 2);
            }

            return $tranche;
        }, $cles);
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
     * null = toute l'organisation ; sinon les agences de l'utilisateur, affichées sous le titre
     * comme sur le tableau de bord des ventes.
     *
     * @return list<string>|null
     */
    private function agencesVisibles(User $user): ?array
    {
        if ($user->voitToutesLesAgences()) {
            return null;
        }

        // Mêmes agences que SiteScopeService::accessibleSiteIds(), lues avec leur nom.
        return $user->sites()->orderBy('sites.nom')->pluck('sites.nom')->values()->all();
    }
}
