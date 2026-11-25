<?php

namespace App\Services\Depenses;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\DepenseComptabilisationService;
use App\Support\Depenses\DepensePerimetre;
use App\Support\Periodes\Periode;
use App\Support\Periodes\TranchesPeriode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord des dépenses, Stat-Dépenses (docs/tableau-de-bord-depenses.md) : strictement
 * consultatif, il lit les dépenses et ne modifie ni leur circuit de validation ni leur
 * comptabilisation.
 *
 * Règles (décisions du 24/11/2026) :
 * - date de référence : `date_depense`, bornes de la période incluses ;
 * - payée = validée (la validation passe l'écriture de sortie de trésorerie, DepenseObserver) ;
 * - en attente = soumise sur la période : une demande, jamais une dette ;
 * - avance récupérable = validée d'une catégorie de DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS,
 *   comme ChargesRapportService et la comptabilité ; toute autre dépense validée est une charge de
 *   l'entreprise (Salarié, Prestataire et Consultant compris) ;
 * - rejetée = statut actuel « Rejeté » : une dépense rejetée puis soumise de nouveau n'est qu'une
 *   ligne, comptée une seule fois, d'après son statut actuel ;
 * - brouillons, annulées et supprimées : exclues partout.
 *
 * Égalités : total = payées + en attente ; payées = charges + avances. Les rejetées n'entrent dans
 * aucune des deux.
 *
 * Périmètre : DepensePerimetre::appliquer, à l'identique de la liste des dépenses (ses agences, celles
 * où son rôle valide, les dépenses qu'il a saisies ; tout pour la vision toutes agences).
 *
 * Trois requêtes d'agrégation sur les dépenses, quel que soit le volume : la période par jour
 * (cartes, mini-courbes, graphique d'évolution), le total de la période de comparaison, et les
 * dépenses validées par type × agence (les deux répartitions). Aucune dépense n'est chargée.
 */
class TableauDeBordDepensesService
{
    /** Périodes proposées : celles de Stat-Ventes et Stat-Commissions, dates libres comprises. */
    public const PERIODES = Periode::TABLEAU_DE_BORD;

    /** Types affichés dans la répartition ; les suivants sont regroupés dans « Autres types ». */
    public const TYPES_AFFICHES = 7;

    /** Index (organization_id, statut, date_depense) de depenses (migration du 30/04/2026). */
    private const INDEX_PERIODE = 'depenses_organization_id_statut_date_depense_index';

    /** Sommes lues par jour : montants et nombres par statut, avances parmi les validées. */
    private const COLONNES = ['payees', 'payees_nombre', 'avances', 'en_attente', 'en_attente_nombre', 'rejetees', 'rejetees_nombre'];

    public function __construct(
        private readonly DepensePerimetre $perimetre,
    ) {}

    /**
     * @param  list<array{value: string, label: string}>  $agences  agences proposées (agencesProposees())
     * @param  ?string  $agence  agence choisie, déjà vérifiée parmi $agences ; null = tout le périmètre
     * @return array<string, mixed>
     */
    public function calculer(User $user, Periode $periode, array $agences, ?string $agence = null, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $precedente = $periode->precedente($maintenant);
        $decoupage = TranchesPeriode::pour($periode);

        // Types de dépense de l'organisation comptés en avance (quelques dizaines au plus) : les sommes
        // les reconnaissent par leur identifiant, sans jointure ligne à ligne sur depense_types.
        $typesAvance = $this->typesAvance($user->organization_id);

        $totaux = array_fill_keys(self::COLONNES, 0.0);
        $parTranche = [];
        $plusAncienne = null;

        foreach ($this->sommes($user, $periode, $agence, $typesAvance, true) as $ligne) {
            // Jour de la dépense (Y-m-d, quel que soit le stockage de la colonne date).
            $jour = substr((string) $ligne->jour, 0, 10);
            $tranche = $decoupage->cle($jour);
            foreach (self::COLONNES as $c) {
                $totaux[$c] += (float) $ligne->{$c};
                $parTranche[$tranche][$c] = ($parTranche[$tranche][$c] ?? 0.0) + (float) $ligne->{$c};
            }
            if ((int) $ligne->en_attente_nombre > 0 && ($plusAncienne === null || $jour < $plusAncienne)) {
                $plusAncienne = $jour;
            }
        }

        $precedents = null;
        if ($precedente !== null) {
            $precedents = array_fill_keys(self::COLONNES, 0.0);
            foreach ($this->sommes($user, $precedente, $agence, $typesAvance, false) as $ligne) {
                foreach (self::COLONNES as $c) {
                    $precedents[$c] += (float) $ligne->{$c};
                }
            }
        }

        $montants = self::montants($totaux);
        $montantsPrecedents = $precedents === null ? null : self::montants($precedents);

        $indicateurs = [];
        foreach (['total', 'payees', 'charges', 'avances'] as $cle) {
            $indicateurs[$cle] = [
                'montant' => $montants[$cle],
                'evolution' => $montantsPrecedents === null ? null : self::evolution($montants[$cle], $montantsPrecedents[$cle]),
            ];
        }

        $repartitions = $this->repartitions($user, $periode, $agence);
        $validees = (int) $totaux['payees_nombre'];
        $rejetees = (int) $totaux['rejetees_nombre'];

        return [
            // Filtre unique de la page : cartes et graphiques portent sur cette période.
            'periode' => [...$periode->pourFront(self::PERIODES), 'libelle' => $periode->libelle()],
            'libelle_comparaison' => $precedente?->libelle(),
            'agences' => $agences,
            'agence' => $agence,
            'perimetre_limite' => ! $this->perimetre->voitToutesLesAgences($user),
            'indicateurs' => $indicateurs,
            'en_attente' => [
                'montant' => $montants['en_attente'],
                'nombre' => (int) $totaux['en_attente_nombre'],
                // Ancienneté depuis la date de la dépense : la date de soumission n'est pas conservée.
                'plus_ancienne' => $plusAncienne,
                'anciennete_jours' => $plusAncienne === null
                    ? null
                    : max(0, (int) CarbonImmutable::parse($plusAncienne)->diffInDays($maintenant->startOfDay(), false)),
            ],
            'rejetees' => [
                'montant' => round($totaux['rejetees'], 2),
                'nombre' => $rejetees,
                // Part des dépenses tranchées (validées ou rejetées) qui sont rejetées, en %.
                'taux' => $validees + $rejetees > 0 ? round($rejetees / ($validees + $rejetees) * 100, 1) : null,
            ],
            'evolution' => [
                'granularite' => $decoupage->granularite(),
                'tranches' => $this->tranches($periode, $decoupage, $parTranche, $maintenant),
            ],
            'repartition_types' => $repartitions['types'],
            'repartition_agences' => $repartitions['agences'],
        ];
    }

    /**
     * Agences que l'utilisateur peut choisir : celles du filtre Agence de la liste des dépenses
     * (DepensePerimetre::sitesProposes). Seule liste acceptée pour `site_id`.
     *
     * @return list<array{value: string, label: string}>
     */
    public function agencesProposees(User $user): array
    {
        return array_map(
            fn (array $s) => ['value' => $s['id'], 'label' => $s['nom']],
            $this->perimetre->sitesProposes($user),
        );
    }

    /**
     * Dépenses visibles de l'utilisateur sur la période, aux statuts lus par le tableau de bord.
     *
     * @param  list<string>  $statuts
     */
    private function base(User $user, Periode $periode, ?string $agence, array $statuts): Builder
    {
        $requete = DB::table('depenses as d');

        // Laissé libre, MySQL choisit l'index (organization_id, depense_type_id), voire un parcours
        // complet, et relit chaque dépense de l'organisation : on lui impose l'index
        // (organization_id, statut, date_depense), qui ne lit que les statuts et la plage de dates
        // utiles — y compris pour « Toutes les périodes » (mesures dans
        // docs/tableau-de-bord-depenses.md). FORCE INDEX est compris par MySQL et MariaDB ; SQLite
        // (tests) garde son propre plan.
        if (in_array($requete->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $requete->forceIndex(self::INDEX_PERIODE);
        }

        $requete->where('d.organization_id', $user->organization_id)
            ->whereNull('d.deleted_at')
            ->whereIn('d.statut', $statuts);

        // Bornes semi-ouvertes sur une colonne DATE (ADR 0057).
        $periode->appliquer($requete, 'd.date_depense', true);
        $this->perimetre->appliquer($requete, $user, 'd.site_id', 'd.user_id');

        if ($agence !== null) {
            $requete->where('d.site_id', $agence);
        }

        return $requete;
    }

    /**
     * Montants et nombres par statut, par jour de dépense pour la période affichée, en un seul total
     * pour la période de comparaison.
     *
     * @param  list<string>  $typesAvance
     * @return list<object>
     */
    private function sommes(User $user, Periode $periode, ?string $agence, array $typesAvance, bool $parJour): array
    {
        $valide = StatutDepense::VALIDE->value;
        $soumis = StatutDepense::SOUMIS->value;
        $rejete = StatutDepense::REJETE->value;
        // Aucun type d'avance dans l'organisation : condition toujours fausse.
        $estAvance = $typesAvance === []
            ? '1 = 0'
            : 'd.depense_type_id IN ('.implode(', ', array_fill(0, count($typesAvance), '?')).')';

        $requete = $this->base($user, $periode, $agence, [$valide, $soumis, $rejete])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as payees', [$valide])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as payees_nombre', [$valide])
            ->selectRaw("COALESCE(SUM(CASE WHEN d.statut = ? AND {$estAvance} THEN d.montant ELSE 0 END), 0) as avances", [$valide, ...$typesAvance])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as en_attente', [$soumis])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as en_attente_nombre', [$soumis])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as rejetees', [$rejete])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as rejetees_nombre', [$rejete]);

        if ($parJour) {
            // Regroupement sur la date brute : les mois sont formés en PHP (TranchesPeriode), sans
            // fonction de date propre à MySQL ou SQLite.
            $requete->addSelect('d.date_depense as jour')->groupBy('d.date_depense');
        }

        return $requete->get()->all();
    }

    /**
     * Dépenses validées par type et par agence de saisie, en une seule lecture (au plus types ×
     * agences lignes) : les deux répartitions en sont tirées en PHP.
     *
     * @return array{types: list<array{id: ?string, label: string, montant: float}>, agences: list<array{id: ?string, label: string, montant: float}>}
     */
    private function repartitions(User $user, Periode $periode, ?string $agence): array
    {
        $lignes = $this->base($user, $periode, $agence, [StatutDepense::VALIDE->value])
            ->groupBy('d.depense_type_id', 'd.site_id')
            ->select('d.depense_type_id as type_id', 'd.site_id')
            ->selectRaw('SUM(d.montant) as montant')
            ->get();

        // Libellés des seuls types présents (types désactivés ou supprimés compris).
        $libelles = DB::table('depense_types')
            ->whereIn('id', $lignes->pluck('type_id')->filter()->unique()->values()->all())
            ->pluck('libelle', 'id');
        $lignes->each(fn ($l) => $l->type_libelle = $libelles[$l->type_id] ?? null);

        return [
            'types' => $this->parType($lignes),
            'agences' => $this->parAgence($user, $lignes),
        ];
    }

    /**
     * Répartition des dépenses validées par type, du plus gros au plus petit ; au-delà de
     * TYPES_AFFICHES types, les suivants forment « Autres types ». Chaque dépense a un seul type :
     * la somme des parts est égale aux dépenses payées.
     *
     * @param  Collection<int, object>  $lignes
     * @return list<array{id: ?string, label: string, montant: float}>
     */
    private function parType(Collection $lignes): array
    {
        $lignes = $lignes
            ->groupBy(fn ($l) => (string) $l->type_id)
            ->map(fn (Collection $parType) => [
                'id' => $parType->first()->type_id === null ? null : (string) $parType->first()->type_id,
                'label' => $parType->first()->type_libelle ?? 'Type inconnu',
                'montant' => round((float) $parType->sum(fn ($l) => (float) $l->montant), 2),
            ])
            ->filter(fn (array $l) => $l['montant'] > 0)
            ->sortByDesc('montant')
            ->values();

        if ($lignes->count() <= self::TYPES_AFFICHES + 1) {
            return $lignes->all();
        }

        return [
            ...$lignes->take(self::TYPES_AFFICHES)->all(),
            [
                'id' => null,
                'label' => 'Autres types',
                'montant' => round($lignes->slice(self::TYPES_AFFICHES)->sum('montant'), 2),
            ],
        ];
    }

    /**
     * Dépenses validées par agence de saisie (`depenses.site_id`), du plus gros au plus petit. Une
     * ancienne dépense sans agence forme « Sans agence » (visible en vision toutes agences, ou de son
     * auteur).
     *
     * @param  Collection<int, object>  $lignes
     * @return list<array{id: ?string, label: string, montant: float}>
     */
    private function parAgence(User $user, Collection $lignes): array
    {
        $parAgence = $lignes->groupBy(fn ($l) => (string) $l->site_id);

        $noms = Site::query()
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', $lignes->pluck('site_id')->filter()->unique()->values()->all())
            ->pluck('nom', 'id');

        return $parAgence
            ->map(function (Collection $lignesAgence) use ($noms) {
                $id = $lignesAgence->first()->site_id;

                return [
                    'id' => $id === null ? null : (string) $id,
                    'label' => $id === null ? 'Sans agence' : (string) ($noms[$id] ?? 'Agence inconnue'),
                    'montant' => round((float) $lignesAgence->sum(fn ($l) => (float) $l->montant), 2),
                ];
            })
            ->filter(fn (array $l) => $l['montant'] > 0)
            ->sortByDesc('montant')
            ->values()
            ->all();
    }

    /**
     * Montants des indicateurs à partir des sommes lues. Charges = payées − avances : une dépense
     * validée dont le type n'a pas de catégorie d'avance reste une charge, jamais un montant perdu.
     *
     * @param  array<string, float>  $sommes
     * @return array{total: float, payees: float, charges: float, avances: float, en_attente: float}
     */
    private static function montants(array $sommes): array
    {
        return [
            'total' => round($sommes['payees'] + $sommes['en_attente'], 2),
            'payees' => round($sommes['payees'], 2),
            'charges' => round($sommes['payees'] - $sommes['avances'], 2),
            'avances' => round($sommes['avances'], 2),
            'en_attente' => round($sommes['en_attente'], 2),
        ];
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
     * Tranches du graphique (TranchesPeriode) : charges et avances (séries du graphique), payées et
     * total (mini-courbes des cartes).
     *
     * @param  array<string, array<string, float>>  $parTranche
     * @return list<array<string, mixed>>
     */
    private function tranches(Periode $periode, TranchesPeriode $decoupage, array $parTranche, CarbonImmutable $maintenant): array
    {
        return array_map(function (array $tranche) use ($parTranche) {
            $sommes = [...array_fill_keys(self::COLONNES, 0.0), ...($parTranche[$tranche['cle']] ?? [])];
            $montants = self::montants($sommes);

            return [
                ...$tranche,
                'total' => $montants['total'],
                'payees' => $montants['payees'],
                'charges' => $montants['charges'],
                'avances' => $montants['avances'],
            ];
        }, $decoupage->tranches($periode, array_map('strval', array_keys($parTranche)), $maintenant));
    }

    /**
     * Identifiants des types de dépense de l'organisation dont la catégorie est une avance
     * récupérable (DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS), types désactivés ou
     * supprimés compris : une dépense garde la catégorie de son type, comme dans ChargesRapportService.
     *
     * @return list<string>
     */
    private function typesAvance(string $organizationId): array
    {
        return DB::table('depense_types')
            ->where('organization_id', $organizationId)
            ->whereIn('categorie', array_map(fn (CategorieDepense $c) => $c->value, DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
