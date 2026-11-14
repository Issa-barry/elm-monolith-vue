<?php

namespace App\Services\Rapports;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Models\Site;
use App\Services\Comptabilite\DepenseComptabilisationService;
use App\Services\Tresorerie\RemisesAgencesService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport d'activité par agence (docs/rapports.md, ADR 0063) : une ligne par agence du périmètre,
 * consolidée à partir des moteurs existants — jamais un second calcul :
 *
 * - ventes, encaissements (par moyen réellement utilisé) et dettes clients : RapportActiviteService,
 *   appelé pour chaque agence avec exactement les règles du rapport d'activité (ventes et dettes sur
 *   l'axe de la commande, encaissements sur l'axe de l'agence qui a reçu l'argent, ADR 0012) ;
 * - remise à la trésorerie principale : RemisesAgencesService (ADR 0016), les mêmes chiffres que la
 *   vue « Remises des agences » — remis = remises reçues par la trésorerie principale pendant la
 *   période, en transit = envoyées non confirmées, reste à remettre = calcul du moment.
 *
 * Aucun montant n'est déduit d'un autre : « reste à remettre » n'est jamais « encaissé − remis »
 * (l'agence garde ses obligations locales, et une remise baisse déjà le reste dès son envoi).
 */
class RapportAgencesService
{
    private const CHARGES_VIDES = [
        'charges_payees' => 0.0,
        'charges_payees_nombre' => 0,
        'charges_en_attente' => 0.0,
        'charges_en_attente_nombre' => 0,
        'charges_avances_tiers' => 0.0,
    ];

    public function __construct(
        private readonly RapportActiviteService $rapports,
        private readonly RemisesAgencesService $remises,
    ) {}

    /**
     * @return array{agences: list<array<string, mixed>>, moyens: list<array{cle: string, libelle: string}>, totaux: array<string, mixed>, central: ?array{id: string, nom: string}}
     */
    public function rapport(RapportPerimetre $p): array
    {
        $sites = $this->sites($p);
        $central = $this->remises->central($p->organizationId);

        $remises = $sites->isEmpty()
            ? collect()
            : collect($this->remises->lignes(
                $p->organizationId,
                Carbon::instance($p->debut()),
                Carbon::instance($p->fin()),
                $sites->pluck('id')->all(),
            ))->keyBy('site_id');

        $charges = $this->charges($p, $sites->pluck('id')->all());

        $agences = $sites
            ->map(fn (Site $site) => [
                ...$this->agence($p, $site, $central?->id, $remises->get($site->id)),
                ...($charges[$site->id] ?? self::CHARGES_VIDES),
            ])
            ->values();

        return [
            'agences' => $agences->all(),
            'moyens' => $this->moyens($agences),
            'totaux' => $this->totaux($agences),
            'central' => $central ? ['id' => $central->id, 'nom' => $central->nom] : null,
        ];
    }

    /** @return Collection<int, Site> */
    private function sites(RapportPerimetre $p): Collection
    {
        if ($p->aucuneAgence()) {
            return collect();
        }

        return Site::query()
            ->where('organization_id', $p->organizationId)
            ->when($p->siteIds !== null, fn ($q) => $q->whereIn('id', $p->siteIds))
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    /**
     * Charges saisies dans chaque agence (module Dépenses, `depenses.site_id`), datées par
     * `date_depense` sur la période (décision du 14/11/2026) :
     *
     * - payées = dépenses VALIDÉES — la validation passe l'écriture de sortie de trésorerie
     *   (DepenseComptabilisationService) : il n'existe pas de dépense validée non payée ;
     * - en attente = dépenses SOUMISES, pas encore validées (rien n'est sorti de la trésorerie) ;
     * - dont avances sur tiers = part des payées comptabilisée en avance récupérable (véhicule,
     *   propriétaire, livreur, parrain), pas en charge de l'entreprise.
     *
     * Brouillons, rejetées, annulées et supprimées exclues. Jamais déduites du remis ni du reste à
     * remettre : la trésorerie de l'agence les intègre déjà par ses propres écritures.
     *
     * @param  list<string>  $siteIds
     * @return array<string, array<string, float|int>>
     */
    private function charges(RapportPerimetre $p, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $valide = StatutDepense::VALIDE->value;
        $soumis = StatutDepense::SOUMIS->value;
        $avances = array_map(fn (CategorieDepense $c) => $c->value, DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS);
        $marques = implode(', ', array_fill(0, count($avances), '?'));

        return DB::table('depenses as d')
            ->leftJoin('depense_types as t', 't.id', '=', 'd.depense_type_id')
            ->where('d.organization_id', $p->organizationId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.site_id', $siteIds)
            ->whereIn('d.statut', [$valide, $soumis])
            // Bornes semi-ouvertes (ADR 0057, comme Periode::appliquer) : sûres quel que soit le
            // stockage de la colonne date (SQLite y ajoute 00:00:00).
            ->where('d.date_depense', '>=', $p->debut()->toDateString())
            ->where('d.date_depense', '<', $p->fin()->startOfDay()->addDay()->toDateString())
            ->groupBy('d.site_id')
            ->select('d.site_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as payees', [$valide])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as payees_nombre', [$valide])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as en_attente', [$soumis])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as en_attente_nombre', [$soumis])
            ->selectRaw("COALESCE(SUM(CASE WHEN d.statut = ? AND t.categorie IN ({$marques}) THEN d.montant ELSE 0 END), 0) as avances", [$valide, ...$avances])
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->site_id => [
                'charges_payees' => round((float) $l->payees, 2),
                'charges_payees_nombre' => (int) $l->payees_nombre,
                'charges_en_attente' => round((float) $l->en_attente, 2),
                'charges_en_attente_nombre' => (int) $l->en_attente_nombre,
                'charges_avances_tiers' => round((float) $l->avances, 2),
            ]])
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $remise  ligne de RemisesAgencesService
     * @return array<string, mixed>
     */
    private function agence(RapportPerimetre $p, Site $site, ?string $centralId, ?array $remise): array
    {
        $perimetre = new RapportPerimetre(
            organizationId: $p->organizationId,
            siteIds: [$site->id],
            agentId: null,
            periode: $p->periode,
            maSituation: false,
        );

        // Résumés seulement (aucune ligne) : les listes restent dans le rapport d'activité de l'agence.
        $ventes = $this->rapports->ventes($perimetre, 0)['resume'];
        $encaissements = $this->rapports->encaissements($perimetre, 0);
        $dettes = $this->rapports->creances($perimetre, 0)['resume'];

        return [
            'site_id' => $site->id,
            'site_nom' => $site->nom,
            'est_tresorerie_principale' => $site->id === $centralId,
            'ventes_nombre' => $ventes['nombre'],
            'ventes_montant' => $ventes['facture'],
            'encaisse_nombre' => $encaissements['resume']['nombre'],
            'encaisse_montant' => $encaissements['resume']['montant'],
            'pour_autres_agences' => $encaissements['resume']['pour_autres_agences_montant'],
            'par_moyen' => collect($encaissements['par_moyen'])->map(fn (array $m) => [
                'cle' => $m['cle'],
                'libelle' => $m['libelle'],
                'mode_paiement' => $m['mode_paiement'],
                'montant' => $m['montant'],
            ])->keyBy('cle')->all(),
            'dettes_nombre' => $dettes['nombre'],
            'dettes_montant' => $dettes['reste'],
            'remise' => $this->remise($site->id === $centralId, $remise),
        ];
    }

    /**
     * La trésorerie principale ne se remet rien à elle-même : ses encaissements sont déjà centralisés.
     *
     * @param  array<string, mixed>|null  $ligne
     * @return array<string, mixed>|null
     */
    private function remise(bool $estCentrale, ?array $ligne): ?array
    {
        if ($estCentrale) {
            return [
                'remis' => null,
                'en_transit' => null,
                'reste_a_remettre' => null,
                'disponible' => null,
                'derniere_remise' => null,
                'statut' => 'tresorerie_principale',
                'statut_label' => 'Trésorerie principale',
            ];
        }

        if ($ligne === null) {
            return null;
        }

        return [
            'remis' => $ligne['deja_remis'],
            'en_transit' => $ligne['en_transit'],
            'reste_a_remettre' => $ligne['reste_a_recevoir'],
            'disponible' => $ligne['disponible'],
            'derniere_remise' => $ligne['derniere_remise'],
            'statut' => $ligne['statut'],
            'statut_label' => $ligne['statut_label'],
        ];
    }

    /**
     * Colonnes de moyens : ceux réellement utilisés par au moins une agence, dans l'ordre du rapport
     * d'activité (espèces, Mobile Money par opérateur, virement, chèque).
     *
     * @param  Collection<int, array<string, mixed>>  $agences
     * @return list<array{cle: string, libelle: string}>
     */
    private function moyens(Collection $agences): array
    {
        return $agences
            ->flatMap(fn (array $a) => array_values($a['par_moyen']))
            ->unique('cle')
            ->sortBy(fn (array $m) => RapportActiviteService::ordreMoyen($m['mode_paiement']).$m['libelle'])
            ->map(fn (array $m) => ['cle' => $m['cle'], 'libelle' => $m['libelle']])
            ->values()
            ->all();
    }

    /**
     * Totaux = somme des lignes affichées (la trésorerie principale n'a ni remis ni reste).
     *
     * @param  Collection<int, array<string, mixed>>  $agences
     * @return array<string, mixed>
     */
    private function totaux(Collection $agences): array
    {
        $somme = fn (callable $valeur) => round((float) $agences->sum($valeur), 2);
        $remises = $agences->pluck('remise')->filter(fn (?array $r) => $r !== null && $r['statut'] !== 'tresorerie_principale');

        return [
            'ventes_nombre' => (int) $agences->sum('ventes_nombre'),
            'ventes_montant' => $somme(fn (array $a) => $a['ventes_montant']),
            'encaisse_nombre' => (int) $agences->sum('encaisse_nombre'),
            'encaisse_montant' => $somme(fn (array $a) => $a['encaisse_montant']),
            'pour_autres_agences' => $somme(fn (array $a) => $a['pour_autres_agences']),
            'par_moyen' => $agences
                ->flatMap(fn (array $a) => array_values($a['par_moyen']))
                ->groupBy('cle')
                ->map(fn (Collection $m) => round((float) $m->sum('montant'), 2))
                ->all(),
            'dettes_nombre' => (int) $agences->sum('dettes_nombre'),
            'dettes_montant' => $somme(fn (array $a) => $a['dettes_montant']),
            'charges_payees' => $somme(fn (array $a) => $a['charges_payees']),
            'charges_payees_nombre' => (int) $agences->sum('charges_payees_nombre'),
            'charges_en_attente' => $somme(fn (array $a) => $a['charges_en_attente']),
            'charges_en_attente_nombre' => (int) $agences->sum('charges_en_attente_nombre'),
            'charges_avances_tiers' => $somme(fn (array $a) => $a['charges_avances_tiers']),
            'remis' => round((float) $remises->sum(fn (array $r) => $r['remis'] ?? 0.0), 2),
            'en_transit' => round((float) $remises->sum(fn (array $r) => $r['en_transit'] ?? 0.0), 2),
            'reste_a_remettre' => round((float) $remises->sum(fn (array $r) => $r['reste_a_remettre'] ?? 0.0), 2),
            // Au moins une agence sans position fiable : le reste total est alors un minimum.
            'reste_incomplet' => $remises->contains(fn (array $r) => $r['reste_a_remettre'] === null),
            'par_statut' => $remises->countBy('statut')->all(),
        ];
    }
}
