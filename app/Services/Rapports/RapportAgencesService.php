<?php

namespace App\Services\Rapports;

use App\Models\Site;
use App\Services\Tresorerie\RemisesAgencesService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
 *
 * « Total encaissé » = somme des encaissements par moyen (14/11/2026) : il se rapproche colonne par
 * colonne des caisses et des comptes Mobile Money, jamais d'un montant tiré des ventes.
 */
class RapportAgencesService
{
    public function __construct(
        private readonly RapportActiviteService $rapports,
        private readonly RemisesAgencesService $remises,
        private readonly ChargesRapportService $charges,
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

        // Charges saisies DANS l'agence (axe `site_id`) : jamais déduites du remis ni du reste à remettre.
        $charges = $this->charges->parSite($p, $sites->pluck('id')->all());

        $agences = $sites
            ->map(fn (Site $site) => [
                ...$this->agence($p, $site, $central?->id, $remises->get($site->id)),
                ...($charges[$site->id] ?? ChargesRapportService::VIDES),
            ])
            ->values();

        $moyens = RapportActiviteService::colonnesMoyens($agences->flatMap(fn (array $a) => array_values($a['par_moyen'])));

        return [
            'agences' => $agences->all(),
            'moyens' => $moyens,
            'totaux' => $this->totaux($agences, $moyens),
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
        $parMoyen = collect($encaissements['par_moyen'])->map(fn (array $m) => [
            'cle' => $m['cle'],
            'libelle' => $m['libelle'],
            'mode_paiement' => $m['mode_paiement'],
            'operateur_mobile_money' => $m['operateur_mobile_money'],
            'montant' => $m['montant'],
        ])->keyBy('cle');

        return [
            'site_id' => $site->id,
            'site_nom' => $site->nom,
            'est_tresorerie_principale' => $site->id === $centralId,
            'ventes_nombre' => $ventes['nombre'],
            'ventes_montant' => $ventes['facture'],
            'encaisse_nombre' => $encaissements['resume']['nombre'],
            'encaisse_montant' => round((float) $parMoyen->sum('montant'), 2),
            'pour_autres_agences' => $encaissements['resume']['pour_autres_agences_montant'],
            'par_moyen' => $parMoyen->all(),
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
     * Totaux = somme des lignes affichées (la trésorerie principale n'a ni remis ni reste) ; le total
     * encaissé est la somme des totaux par moyen.
     *
     * @param  Collection<int, array<string, mixed>>  $agences
     * @param  list<array{cle: string, libelle: string}>  $moyens
     * @return array<string, mixed>
     */
    private function totaux(Collection $agences, array $moyens): array
    {
        $somme = fn (callable $valeur) => round((float) $agences->sum($valeur), 2);
        $remises = $agences->pluck('remise')->filter(fn (?array $r) => $r !== null && $r['statut'] !== 'tresorerie_principale');
        $parMoyen = collect($moyens)
            ->mapWithKeys(fn (array $m) => [$m['cle'] => $somme(fn (array $a) => $a['par_moyen'][$m['cle']]['montant'] ?? 0.0)]);

        return [
            'ventes_nombre' => (int) $agences->sum('ventes_nombre'),
            'ventes_montant' => $somme(fn (array $a) => $a['ventes_montant']),
            'encaisse_nombre' => (int) $agences->sum('encaisse_nombre'),
            'encaisse_montant' => round((float) $parMoyen->sum(), 2),
            'pour_autres_agences' => $somme(fn (array $a) => $a['pour_autres_agences']),
            'par_moyen' => $parMoyen->all(),
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
