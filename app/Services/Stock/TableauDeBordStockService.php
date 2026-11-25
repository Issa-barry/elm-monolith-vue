<?php

namespace App\Services\Stock;

use App\Enums\MotifAjustementStock;
use App\Enums\StatutTransfert;
use App\Enums\StockStatut;
use App\Models\Parametre;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Services\MouvementStockMotifService;
use App\Services\StockStatutService;
use App\Support\Periodes\Periode;
use App\Support\Periodes\TranchesPeriode;
use App\Support\Stock\EtatStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tableau de bord du stock, Stat-Stock (docs/tableau-de-bord-stock.md) : strictement consultatif,
 * il lit l'état et le journal du stock sans rien modifier.
 *
 * - **Article** = une variante active d'un produit dont le type gère le stock. Les quantités ne
 *   s'additionnent jamais entre articles (pack 350 ml, pack 1,5 L, sachets… : aucune unité commune
 *   n'existe dans le catalogue) : cartes, graphique et stock par site portent sur l'article choisi,
 *   la synthèse présente chaque article sans total. Seul le nombre d'alertes (couples article ×
 *   site) se compte tous articles confondus.
 * - **État actuel** : EtatStock, la requête de la page Stock (physique, réservé, disponible =
 *   physique − réservé, seuil, disponibilité et alerte par site) ; statut par
 *   StockStatutService::statutPour().
 * - **Alertes** : compteur = disponible sur le site ET alerte active (règle des badges, STOCK-
 *   ALERTE-007) ; tableau = disponible sur le site seulement (règle des écrans opérationnels).
 * - **Flux de la période** (date métier `date`) : mouvements par motif (MouvementStockMotifService::
 *   expressionSql), hors mouvements annulés et leurs contre-mouvements.
 * - **En transit** : quantités chargées des transferts au statut Transit à destination des sites.
 * - **Écarts de réception** : transferts reçus sur la période à destination des sites, écart =
 *   reçu + retourné − chargé (TransfertLigne::ecart), jamais remis en stock (ADR 0032).
 */
class TableauDeBordStockService
{
    /** Périodes proposées : celles des autres tableaux de bord, dates libres comprises. */
    public const PERIODES = Periode::TABLEAU_DE_BORD;

    /** Motifs de sortie comptés en pertes et casses. */
    private const MOTIFS_PERTES = [MotifAjustementStock::PERTE, MotifAjustementStock::CASSE];

    /** Index (organization_id, created_at), imposé pour lire une période bornée (voir mouvements()). */
    private const INDEX_SAISIE = 'mouvements_stock_organization_id_created_at_index';

    /** Index de la clé étrangère `annule_par_id`, écarté des lectures de flux (voir mouvements()). */
    private const INDEX_ANNULATION = 'mouvements_stock_annule_par_id_foreign';

    public function __construct(
        private readonly StockStatutService $stockStatut,
    ) {}

    /**
     * @param  list<array{value: string, label: string}>  $agences  agences proposées (agencesProposees())
     * @param  ?string  $agence  agence choisie, déjà vérifiée ; null = toutes les agences du périmètre
     * @param  ?string  $article  variante choisie, déjà vérifiée parmi articles() ; null = la première
     * @return array<string, mixed>
     */
    public function calculer(User $user, Periode $periode, array $agences, ?string $agence, ?string $article, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $orgId = (string) $user->organization_id;
        $siteIds = $agence !== null ? [$agence] : array_column($agences, 'value');
        $seuil = Parametre::getSeuilStockFaible($orgId);

        $etat = $siteIds === [] ? collect() : EtatStock::requete($orgId, $siteIds, $seuil)
            ->orderBy('p.nom')->orderBy('pv.position')->orderBy('s.nom')
            ->get()
            ->map(function ($ligne) {
                $ligne->statut = $this->stockStatut->statutPour((int) $ligne->qte_disponible, (int) $ligne->seuil_effectif);

                return $ligne;
            });

        $articles = $this->articles($orgId, $etat);
        // Article demandé : refusé avec un message s'il n'est pas dans le périmètre, jamais remplacé
        // en silence ; sans demande, le premier article de la page Stock.
        if ($article !== null && ! in_array($article, array_column($articles, 'value'), true)) {
            throw ValidationException::withMessages(['article' => 'Article inconnu ou hors de votre périmètre.']);
        }
        $article ??= $articles[0]['value'] ?? null;

        $transit = $this->transit($orgId, $siteIds);
        $decoupage = TranchesPeriode::pour($periode);
        $journal = $this->journal($orgId, $siteIds, $periode, $decoupage);
        $flux = $this->fluxParArticle($journal);
        $ecarts = $this->ecarts($orgId, $siteIds, $periode);

        return [
            'periode' => [...$periode->pourFront(self::PERIODES), 'libelle' => $periode->libelle()],
            'agences' => $agences,
            'agence' => $agence,
            'perimetre_limite' => ! $user->voitToutesLesAgences(),
            'articles' => $articles,
            'article' => $article,
            'etat' => $this->etatArticle($etat, $transit, $article),
            'alertes' => $this->compteurAlertes($etat),
            'flux_periode' => $this->fluxArticle($flux, $ecarts, $article),
            'evolution' => $this->evolution($journal, $decoupage, $periode, $article, $maintenant),
            'sites' => $this->sitesArticle($etat, $article),
            'synthese' => $this->synthese($etat, $articles, $transit, $flux, $ecarts),
            'alertes_lignes' => $this->lignesAlerte($etat, $articles),
        ];
    }

    /**
     * Agences que l'utilisateur peut choisir : celles de la page Stock (EtatStock::sitesConsultables).
     *
     * @return list<array{value: string, label: string}>
     */
    public function agencesProposees(User $user): array
    {
        return EtatStock::sitesConsultables($user, (string) $user->organization_id)
            ->map(fn ($site) => ['value' => (string) $site->id, 'label' => $site->nom])
            ->values()
            ->all();
    }

    /**
     * Articles présents dans l'état (variantes actives des produits gérés en stock), dans l'ordre de
     * la page Stock. Libellé : le produit, suivi des options pour une variante qui n'est pas celle
     * par défaut.
     *
     * @return list<array{value: string, label: string, produit_id: string}>
     */
    private function articles(string $orgId, Collection $etat): array
    {
        $premieres = $etat->unique('variante_id');
        $variantes = ProduitVariante::with('valeurs.option')
            ->where('organization_id', $orgId)
            ->whereIn('id', $premieres->pluck('variante_id')->all())
            ->get()
            ->keyBy('id');

        return $premieres->map(function ($ligne) use ($variantes) {
            $variante = $variantes->get($ligne->variante_id);
            $libelle = $variante === null || $variante->combo_hash === ProduitVariante::COMBO_HASH_DEFAUT
                ? $ligne->produit_nom
                : $ligne->produit_nom.' — '.EtatStock::libelleVariante($variante);

            return ['value' => (string) $ligne->variante_id, 'label' => $libelle, 'produit_id' => (string) $ligne->produit_id];
        })->values()->all();
    }

    /**
     * @return array{physique: int, reservee: int, disponible: int, en_transit: int, transferts_en_transit: int}
     */
    private function etatArticle(Collection $etat, array $transit, ?string $article): array
    {
        $lignes = $etat->where('variante_id', $article);

        return [
            'physique' => (int) $lignes->sum('qte_physique'),
            'reservee' => (int) $lignes->sum('qte_reservee'),
            'disponible' => (int) $lignes->sum('qte_disponible'),
            'en_transit' => (int) ($transit[$article]['quantite'] ?? 0),
            'transferts_en_transit' => (int) ($transit[$article]['transferts'] ?? 0),
        ];
    }

    /**
     * Couples article × site à signaler (règle des badges) : disponibles sur le site, alerte active,
     * en rupture, stock faible ou stock négatif.
     *
     * @return array{total: int, ruptures: int, faibles: int, negatifs: int}
     */
    private function compteurAlertes(Collection $etat): array
    {
        $alertes = $etat->filter(fn ($l) => (bool) $l->disponible_sur_site && (bool) $l->alerte_active
            && $l->statut !== StockStatut::DISPONIBLE);

        return [
            'total' => $alertes->count(),
            'ruptures' => $alertes->where('statut', StockStatut::RUPTURE)->count(),
            'faibles' => $alertes->where('statut', StockStatut::STOCK_FAIBLE)->count(),
            'negatifs' => $alertes->where('statut', StockStatut::STOCK_NEGATIF)->count(),
        ];
    }

    /**
     * Quantité chargée et nombre de transferts au statut Transit, par variante, à destination des
     * sites : déjà sortis du site source, pas encore entrés au site destination.
     *
     * @param  list<string>  $siteIds
     * @return array<string, array{quantite: int, transferts: int}>
     */
    private function transit(string $orgId, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return DB::table('transfert_lignes as tl')
            ->join('transferts_logistiques as t', 't.id', '=', 'tl.transfert_logistique_id')
            ->where('t.organization_id', $orgId)
            ->whereNull('t.deleted_at')
            ->where('t.statut', StatutTransfert::TRANSIT->value)
            ->whereIn('t.site_destination_id', $siteIds)
            ->groupBy('tl.variante_id')
            ->select('tl.variante_id')
            ->selectRaw('COALESCE(SUM(tl.quantite_chargee), 0) as quantite')
            ->selectRaw('COUNT(DISTINCT t.id) as transferts')
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->variante_id => ['quantite' => (int) $l->quantite, 'transferts' => (int) $l->transferts]])
            ->all();
    }

    /**
     * Mouvements de la période sur les sites, hors mouvements annulés et leurs contre-mouvements
     * (paire neutre, jamais une entrée ni une sortie réelle).
     *
     * @param  list<string>  $siteIds
     */
    private function mouvements(string $orgId, array $siteIds, Periode $periode): Builder
    {
        $requete = DB::table('mouvements_stock as m');
        // Plan imposé (docs/tableau-de-bord-stock.md, Performances) : laissé libre, MySQL relit le
        // journal par accès aléatoires (index org_site_variante, ou `annule_par_id`, presque toujours
        // NULL, croisé avec un autre). Période bornée : l'index (organization_id, created_at), à
        // partir du début de la période (condition ci-dessous) ; « Toutes les périodes » : sans
        // l'index `annule_par_id`. Compris par MySQL et MariaDB ; SQLite (tests) garde son plan.
        if (in_array($requete->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $periode->debut !== null
                ? $requete->forceIndex(self::INDEX_SAISIE)
                : $requete->ignoreIndex(self::INDEX_ANNULATION);
        }

        $requete->where('m.organization_id', $orgId)
            ->whereIn('m.site_id', $siteIds)
            ->whereNull('m.annule_par_id')
            // `a.annule_par_id IS NOT NULL`, impliqué par l'égalité, borne la sous-requête aux seules
            // annulations : MySQL la matérialise sans relire les mouvements jamais annulés.
            ->whereNotExists(fn (Builder $q) => $q->from('mouvements_stock as a')
                ->whereNotNull('a.annule_par_id')
                ->whereColumn('a.annule_par_id', 'm.id'));

        // Date métier, bornes incluses (ADR 0057).
        $periode->appliquer($requete, 'm.date', true);

        // La date métier n'est jamais postérieure au jour de saisie (STOCK-DATE-002, 004 et 005 ;
        // anciens mouvements : date = DATE(created_at)) : un mouvement de la période a été saisi
        // depuis son début. Cette condition, toujours vraie pour les lignes retenues, ne change pas
        // le résultat ; elle permet de lire la période dans l'index (organization_id, created_at)
        // au lieu de tout le journal de l'organisation, la date métier n'ayant pas d'index.
        if ($periode->debut !== null) {
            $requete->where('m.created_at', '>=', $periode->debut->toDateTimeString());
        }

        return $requete;
    }

    /**
     * Unique lecture du journal de la période : quantités par article, tranche du graphique (jour,
     * ou mois au-delà de 31 jours), sens et motif. Les flux de la synthèse et le graphique de
     * l'article choisi en sont tirés sans relire les mouvements ; quelques milliers de lignes au plus
     * (articles × tranches × motifs), quel que soit le volume du journal.
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, object{variante_id: string, tranche: ?string, sens: string, motif: string, quantite: int|string}>
     */
    private function journal(string $orgId, array $siteIds, Periode $periode, TranchesPeriode $decoupage): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        [$motif, $liaisons] = MouvementStockMotifService::expressionSql('m');
        // Même clé que TranchesPeriode::cle() : le jour (Y-m-d) ou ses sept premiers caractères (Y-m).
        $tranche = $decoupage->parMois ? 'SUBSTR(m.date, 1, 7)' : 'm.date';

        return $this->mouvements($orgId, $siteIds, $periode)
            ->selectRaw("m.produit_variante_id as variante_id, {$tranche} as tranche, m.type as sens, {$motif} as motif, SUM(m.quantite) as quantite", $liaisons)
            ->groupBy('m.produit_variante_id', 'tranche', 'm.type', 'motif')
            ->get();
    }

    /**
     * Entrées, sorties et pertes-casses de la période, par variante.
     *
     * @param  Collection<int, object>  $journal  journal()
     * @return array<string, array{entrees: int, sorties: int, pertes_casses: int}>
     */
    private function fluxParArticle(Collection $journal): array
    {
        $pertes = array_map(fn (MotifAjustementStock $m) => $m->value, self::MOTIFS_PERTES);

        $flux = [];
        foreach ($journal as $l) {
            $article = (string) $l->variante_id;
            $flux[$article] ??= ['entrees' => 0, 'sorties' => 0, 'pertes_casses' => 0];
            $flux[$article][$l->sens === 'entree' ? 'entrees' : 'sorties'] += (int) $l->quantite;
            if ($l->sens === 'sortie' && in_array($l->motif, $pertes, true)) {
                $flux[$article]['pertes_casses'] += (int) $l->quantite;
            }
        }

        return $flux;
    }

    /**
     * Écarts des transferts reçus sur la période à destination des sites, par variante : manquants
     * (reçu + retourné < chargé) et surplus (au-delà du chargé).
     *
     * @param  list<string>  $siteIds
     * @return array<string, array{manquants: int, surplus: int}>
     */
    private function ecarts(string $orgId, array $siteIds, Periode $periode): array
    {
        if ($siteIds === []) {
            return [];
        }
        $ecart = 'tl.quantite_recue + COALESCE(tl.quantite_retournee, 0) - tl.quantite_chargee';

        $requete = DB::table('transfert_lignes as tl')
            ->join('transferts_logistiques as t', 't.id', '=', 'tl.transfert_logistique_id')
            ->where('t.organization_id', $orgId)
            ->whereNull('t.deleted_at')
            ->where('t.statut', '!=', StatutTransfert::ANNULE->value)
            ->whereIn('t.site_destination_id', $siteIds)
            ->whereNotNull('t.date_arrivee_reelle')
            ->whereNotNull('tl.quantite_chargee')
            ->whereNotNull('tl.quantite_recue');
        $periode->appliquer($requete, 't.date_arrivee_reelle', true);

        return $requete
            ->groupBy('tl.variante_id')
            ->select('tl.variante_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ecart} < 0 THEN -({$ecart}) ELSE 0 END), 0) as manquants")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ecart} > 0 THEN {$ecart} ELSE 0 END), 0) as surplus")
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->variante_id => ['manquants' => (int) $l->manquants, 'surplus' => (int) $l->surplus]])
            ->all();
    }

    /**
     * @return array{entrees: int, sorties: int, pertes_casses: int, ecarts_manquants: int, ecarts_surplus: int}
     */
    private function fluxArticle(array $flux, array $ecarts, ?string $article): array
    {
        return [
            'entrees' => (int) ($flux[$article]['entrees'] ?? 0),
            'sorties' => (int) ($flux[$article]['sorties'] ?? 0),
            'pertes_casses' => (int) ($flux[$article]['pertes_casses'] ?? 0),
            'ecarts_manquants' => (int) ($ecarts[$article]['manquants'] ?? 0),
            'ecarts_surplus' => (int) ($ecarts[$article]['surplus'] ?? 0),
        ];
    }

    /**
     * Entrées et sorties de l'article par jour ou par mois (TranchesPeriode), une série par motif
     * et par sens. Les sorties sont négatives, pour un graphique de part et d'autre de zéro.
     *
     * @param  Collection<int, object>  $journal  journal()
     * @return array{granularite: string, tranches: list<array{cle: string, label: string}>, series: list<array{motif: string, label: string, sens: string, valeurs: list<int>}>}
     */
    private function evolution(Collection $journal, TranchesPeriode $decoupage, Periode $periode, ?string $article, CarbonImmutable $maintenant): array
    {
        $parSerie = [];
        $clesAvecDonnees = [];
        foreach ($journal as $l) {
            if ((string) $l->variante_id !== $article) {
                continue;
            }
            // Clé de tranche, quel que soit le stockage de la colonne date (Y-m-d ou date-heure).
            $tranche = $decoupage->cle((string) $l->tranche);
            $cle = $l->sens.'|'.$l->motif;
            $parSerie[$cle][$tranche] = ($parSerie[$cle][$tranche] ?? 0) + (int) $l->quantite;
            $clesAvecDonnees[$tranche] = true;
        }

        $tranches = array_map(
            fn (array $t) => ['cle' => (string) $t['cle'], 'label' => (string) $t['label']],
            $decoupage->tranches($periode, array_map('strval', array_keys($clesAvecDonnees)), $maintenant),
        );

        $series = [];
        ksort($parSerie);
        foreach ($parSerie as $cle => $valeurs) {
            [$sens, $motif] = explode('|', $cle, 2);
            $series[] = [
                'motif' => $motif,
                'label' => MouvementStockMotifService::libelle($motif),
                'sens' => $sens,
                'valeurs' => array_map(
                    fn (array $t) => ($sens === 'sortie' ? -1 : 1) * (int) ($valeurs[$t['cle']] ?? 0),
                    $tranches,
                ),
            ];
        }

        return ['granularite' => $decoupage->granularite(), 'tranches' => $tranches, 'series' => $series];
    }

    /**
     * Stock de l'article par site : sites où l'article est disponible, ou qui en ont encore en stock.
     *
     * @return list<array{id: string, label: string, physique: int, reservee: int, disponible: int, disponible_sur_site: bool}>
     */
    private function sitesArticle(Collection $etat, ?string $article): array
    {
        return $etat->where('variante_id', $article)
            ->filter(fn ($l) => (bool) $l->disponible_sur_site || (int) $l->qte_physique !== 0)
            ->map(fn ($l) => [
                'id' => (string) $l->site_id,
                'label' => $l->site_nom,
                'physique' => (int) $l->qte_physique,
                'reservee' => (int) $l->qte_reservee,
                'disponible' => (int) $l->qte_disponible,
                'disponible_sur_site' => (bool) $l->disponible_sur_site,
            ])
            ->sortByDesc('physique')
            ->values()
            ->all();
    }

    /**
     * Une ligne par article, sans total : les unités diffèrent d'un article à l'autre.
     *
     * @param  list<array{value: string, label: string, produit_id: string}>  $articles
     * @return list<array<string, mixed>>
     */
    private function synthese(Collection $etat, array $articles, array $transit, array $flux, array $ecarts): array
    {
        return array_map(function (array $article) use ($etat, $transit, $flux, $ecarts) {
            $id = $article['value'];
            $lignes = $etat->where('variante_id', $id);

            return [
                'id' => $id,
                'label' => $article['label'],
                'physique' => (int) $lignes->sum('qte_physique'),
                'reservee' => (int) $lignes->sum('qte_reservee'),
                'disponible' => (int) $lignes->sum('qte_disponible'),
                'en_transit' => (int) ($transit[$id]['quantite'] ?? 0),
                'entrees' => (int) ($flux[$id]['entrees'] ?? 0),
                'sorties' => (int) ($flux[$id]['sorties'] ?? 0),
                'pertes_casses' => (int) ($flux[$id]['pertes_casses'] ?? 0),
                'ecarts_manquants' => (int) ($ecarts[$id]['manquants'] ?? 0),
            ];
        }, $articles);
    }

    /**
     * Couples article × site à surveiller (règle des écrans opérationnels : disponibles sur le site,
     * statut autre que Disponible), les plus graves d'abord : négatif, rupture, stock faible.
     *
     * @param  list<array{value: string, label: string, produit_id: string}>  $articles
     * @return list<array<string, mixed>>
     */
    private function lignesAlerte(Collection $etat, array $articles): array
    {
        $libelles = array_column($articles, 'label', 'value');
        $gravite = [StockStatut::STOCK_NEGATIF->value => 0, StockStatut::RUPTURE->value => 1, StockStatut::STOCK_FAIBLE->value => 2];

        return $etat->filter(fn ($l) => (bool) $l->disponible_sur_site && $l->statut !== StockStatut::DISPONIBLE)
            ->sortBy(fn ($l) => [$gravite[$l->statut->value] ?? 3, $libelles[$l->variante_id] ?? '', $l->site_nom])
            ->map(fn ($l) => [
                'produit_id' => (string) $l->produit_id,
                'variante_id' => (string) $l->variante_id,
                'article' => $libelles[$l->variante_id] ?? $l->produit_nom,
                'site_id' => (string) $l->site_id,
                'site' => $l->site_nom,
                'physique' => (int) $l->qte_physique,
                'reservee' => (int) $l->qte_reservee,
                'disponible' => (int) $l->qte_disponible,
                'seuil' => (int) $l->seuil_effectif,
                'statut' => $l->statut->value,
                'statut_label' => $l->statut->label(),
                'alerte_active' => (bool) $l->alerte_active,
            ])
            ->values()
            ->all();
    }
}
