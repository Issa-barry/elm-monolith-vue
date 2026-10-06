<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Remises des agences à la trésorerie principale (ADR 0016, vue « Remises des agences ») : ce que
 * chaque agence doit encore remettre, ce qui est en transit, ce qui a été reçu.
 *
 * Montants mesurés, jamais un attendu figé :
 *  - reste à recevoir = calcul du moment de FinancementAgenceService (`total_a_remettre`), quelle
 *    que soit la période choisie — il baisse déjà dès qu'une remise quitte l'agence ;
 *  - en transit       = remises envoyées, pas encore confirmées par la trésorerie principale ;
 *  - déjà remis       = remises confirmées pendant la période ;
 *  - attendu          = reste + en transit + déjà remis (déduit, jamais « attendu − remis »).
 *
 * Une remise est un mouvement de fonds d'une agence vers la trésorerie principale (transfert entre
 * agences ou règlement inter-agences) : un financement va dans l'autre sens et n'est jamais compté.
 * Lecture seule.
 */
class RemisesAgencesService
{
    public const STATUT_DONNEES_INCOMPLETES = 'donnees_incompletes';

    public const STATUT_REMISE_EN_COURS = 'remise_en_cours';

    public const STATUT_PARTIELLEMENT_REMIS = 'partiellement_remis';

    public const STATUT_REMIS = 'remis';

    public const STATUT_A_REMETTRE = 'a_remettre';

    public const STATUT_RIEN_A_REMETTRE = 'rien_a_remettre';

    public const LIBELLES = [
        self::STATUT_DONNEES_INCOMPLETES => 'Données incomplètes',
        self::STATUT_REMISE_EN_COURS => 'Remise en cours',
        self::STATUT_PARTIELLEMENT_REMIS => 'Partiellement remis',
        self::STATUT_REMIS => 'Remis',
        self::STATUT_A_REMETTRE => 'À remettre',
        self::STATUT_RIEN_A_REMETTRE => 'Rien à remettre',
    ];

    public function __construct(
        private readonly FinancementAgenceService $financement,
        private readonly SiteCentralTresorerieResolver $siteCentral,
    ) {}

    public function central(string $organizationId): ?Site
    {
        return $this->siteCentral->centralOuNull($organizationId);
    }

    /**
     * @param  list<string>|null  $siteIds  agences visibles (null = toutes)
     * @return list<array<string, mixed>>
     */
    public function lignes(string $organizationId, Carbon $debut, Carbon $fin, ?array $siteIds = null): array
    {
        $central = $this->central($organizationId);
        if (! $central) {
            return [];
        }

        $remises = $this->remises($organizationId, $central->id)->groupBy('site_origine_id');

        return collect($this->positionsDuMoment($organizationId))
            ->filter(fn (array $row) => $row['site_id'] !== null && $row['site_id'] !== $central->id)
            ->when($siteIds !== null, fn (Collection $rows) => $rows->filter(fn (array $row) => in_array($row['site_id'], $siteIds, true)))
            ->map(fn (array $row) => $this->ligne($row, $remises->get($row['site_id'], collect()), $debut, $fin))
            ->values()
            ->all();
    }

    /** @param  list<array<string, mixed>>  $lignes */
    public function totaux(array $lignes): array
    {
        $somme = fn (string $champ) => round((float) collect($lignes)->sum(fn (array $l) => $l[$champ] ?? 0.0), 2);

        return [
            'attendu' => $somme('attendu'),
            'deja_remis' => $somme('deja_remis'),
            'en_transit' => $somme('en_transit'),
            'reste_a_recevoir' => $somme('reste_a_recevoir'),
            'par_statut' => collect($lignes)->countBy('statut')->all(),
        ];
    }

    /**
     * Détail d'une agence : sa ligne et ses remises — celles envoyées pendant la période et toutes
     * celles encore en transit, les plus récentes d'abord.
     *
     * @return array{ligne: ?array<string, mixed>, remises: Collection<int, MouvementFonds>}
     */
    public function detail(string $organizationId, Site $agence, Carbon $debut, Carbon $fin): array
    {
        $central = $this->central($organizationId);
        if (! $central) {
            return ['ligne' => null, 'remises' => collect()];
        }

        $remises = $this->remises($organizationId, $central->id, $agence->id);
        $row = collect($this->positionsDuMoment($organizationId))->firstWhere('site_id', $agence->id);

        return [
            'ligne' => $row ? $this->ligne($row, $remises, $debut, $fin) : null,
            'remises' => $remises
                ->filter(fn (MouvementFonds $m) => in_array($m->statut, [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE], true)
                    || ($m->date_envoi !== null && $m->date_envoi->betweenIncluded($debut, $fin)))
                ->sortByDesc(fn (MouvementFonds $m) => $m->date_envoi?->getTimestamp() ?? 0)
                ->values(),
        ];
    }

    /** Supports actifs de la trésorerie principale : ceux qui peuvent recevoir une remise. */
    public function supportsDeReception(string $organizationId): Collection
    {
        $central = $this->central($organizationId);

        return $central
            ? CompteTresorerie::forOrg($organizationId)->agence()->actifs()->where('site_id', $central->id)->orderBy('libelle')->get(['id', 'libelle', 'type'])
            : collect();
    }

    /**
     * Position de chaque agence à l'instant présent — même calcul et même vue par défaut (mois en
     * cours, mois complet) que l'écran Financement, pour que les deux écrans affichent le même
     * montant à remettre.
     *
     * @return list<array<string, mixed>>
     */
    private function positionsDuMoment(string $organizationId): array
    {
        return $this->financement->calculerPourEcheance($organizationId, now()->year, now()->month, 'mensuel');
    }

    /** @return Collection<int, MouvementFonds> remises vers la trésorerie principale, hors brouillons et annulations */
    private function remises(string $organizationId, string $centralId, ?string $agenceId = null): Collection
    {
        return MouvementFonds::where('organization_id', $organizationId)
            ->where('site_destination_id', $centralId)
            ->where('site_origine_id', '!=', $centralId)
            ->when($agenceId, fn ($q) => $q->where('site_origine_id', $agenceId))
            ->whereIn('nature', [NatureMouvementFonds::INTER_SITES->value, NatureMouvementFonds::REGLEMENT_AGENCES->value])
            ->whereNotIn('statut', [StatutMouvementFonds::BROUILLON->value, StatutMouvementFonds::ANNULE->value])
            ->with(['compteTresorerieOrigine:id,libelle', 'compteTresorerieDestination:id,libelle', 'expediteur.personne', 'receptionnaire.personne'])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row  ligne de FinancementAgenceService
     * @param  Collection<int, MouvementFonds>  $remises
     * @return array<string, mixed>
     */
    private function ligne(array $row, Collection $remises, Carbon $debut, Carbon $fin): array
    {
        $enTransit = round((float) $remises
            ->filter(fn (MouvementFonds $m) => in_array($m->statut, [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE], true))
            ->sum('montant'), 2);
        $dejaRemis = round((float) $remises
            ->filter(fn (MouvementFonds $m) => $m->statut === StatutMouvementFonds::RECU
                && $m->date_reception !== null && $m->date_reception->betweenIncluded($debut, $fin))
            ->sum('montant'), 2);
        $reste = $row['total_a_remettre'] === null ? null : round((float) $row['total_a_remettre'], 2);
        $derniere = $remises->max(fn (MouvementFonds $m) => $m->date_envoi);

        $statut = match (true) {
            $reste === null => self::STATUT_DONNEES_INCOMPLETES,
            $enTransit > 0.0 => self::STATUT_REMISE_EN_COURS,
            $dejaRemis > 0.0 && $reste > 0.0 => self::STATUT_PARTIELLEMENT_REMIS,
            $dejaRemis > 0.0 => self::STATUT_REMIS,
            $reste > 0.0 => self::STATUT_A_REMETTRE,
            default => self::STATUT_RIEN_A_REMETTRE,
        };

        return [
            'site_id' => $row['site_id'],
            'site_nom' => $row['site_nom'],
            'attendu' => $reste === null ? null : round($reste + $enTransit + $dejaRemis, 2),
            'deja_remis' => $dejaRemis,
            'en_transit' => $enTransit,
            'reste_a_recevoir' => $reste,
            'remise_obligatoire' => $row['remise_obligatoire'],
            'excedent_a_remettre' => $row['excedent_a_remettre'],
            'derniere_remise' => $derniere?->toDateString(),
            'statut' => $statut,
            'statut_label' => self::LIBELLES[$statut],
        ];
    }
}
