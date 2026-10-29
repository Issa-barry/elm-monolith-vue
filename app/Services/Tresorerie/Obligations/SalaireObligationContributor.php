<?php

namespace App\Services\Tresorerie\Obligations;

use App\Contracts\Rh\SourceDonneesPaie;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Salaires — mensuel, sourcé directement sur PaieLigne/PaiePeriode (jamais via
 * PaiementPeriode/PaiementFiche comme livreurs/propriétaires) : bien que
 * TypePeriodePaiement::periodicity() vaille "quinzaine" pour SALARIE aussi,
 * PeriodeCalculatorService::calculerSalaries() sélectionne les PaieLigne par
 * MOIS entier (whereMonth) — calculer P1 et P2 "salarié" séparément via ce
 * chemin dupliquerait le salaire mensuel complet sur chacune. PaieLigne (un
 * employé x un mois, contrainte unique) n'a pas cette ambiguïté.
 *
 * Tant que la Comptabilité n'a pas repris la transmission RH du mois (la période n'a aucune ligne,
 * ADR 0041), le besoin est estimé par les RH (SourceDonneesPaie::estimationVersements : version
 * transmise, sinon calcul du moment), avant retenues. Rien n'est enregistré par la prévision.
 */
class SalaireObligationContributor implements ObligationContributor
{
    public function __construct(
        private readonly SourceDonneesPaie $sourcePaie,
    ) {}

    public function colonnes(): array
    {
        return ['salaires'];
    }

    public function echeancesParColonne(): array
    {
        return ['salaires' => 'fin_de_mois'];
    }

    public function collecter(string $organizationId, int $annee, int $mois, array &$besoin): void
    {
        $lignes = $this->lignesDuMois($organizationId, $annee, $mois);

        if ($lignes === null) {
            $this->sourcePaie->estimationVersements($organizationId, $annee, $mois)
                ->each(function (array $estimation) use (&$besoin) {
                    ObligationAccumulator::ajouter($besoin, $estimation['site_id'], 'salaires', $estimation['montant'], $estimation['montant']);
                });

            return;
        }

        $lignes->each(function (PaieLigne $ligne) use (&$besoin) {
            ObligationAccumulator::ajouter($besoin, $ligne->employe?->site_id, 'salaires', (float) $ligne->reste_a_payer, (float) $ligne->net);
        });
    }

    public function arrieres(string $organizationId, CarbonInterface $debutMois, array &$arrieres): void
    {
        $annee = $debutMois->year;
        $mois = $debutMois->month;

        PaieLigne::where('reste_a_payer', '>', 0)
            ->whereHas('periode', fn ($q) => $q
                ->where('organization_id', $organizationId)
                ->where(fn ($avant) => $avant
                    ->where('annee', '<', $annee)
                    ->orWhere(fn ($meme) => $meme->where('annee', $annee)->where('mois', '<', $mois))))
            ->with('employe:id,site_id')
            ->get(['id', 'employe_id', 'reste_a_payer'])
            ->each(function (PaieLigne $ligne) use (&$arrieres) {
                ObligationAccumulator::ajouterArriere($arrieres, $ligne->employe?->site_id, (float) $ligne->reste_a_payer);
            });
    }

    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        $lignes = $this->lignesDuMois($organizationId, $annee, $mois);

        if ($lignes === null) {
            return [
                'salaires' => $this->sourcePaie->estimationVersements($organizationId, $annee, $mois)
                    ->filter(fn (array $e) => $e['site_id'] === $siteId && $e['montant'] > 0.0)
                    ->map(fn (array $e) => [
                        'id' => 'estimation-'.$e['employe_id'],
                        'nom' => $e['nom'],
                        'net' => $e['montant'],
                        'deja_paye' => 0.0,
                        'reste_a_payer' => $e['montant'],
                        'statut' => 'estimation',
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'salaires' => $lignes
                ->filter(fn (PaieLigne $l) => $l->employe?->site_id === $siteId && (float) $l->reste_a_payer > 0.0)
                ->map(fn (PaieLigne $l) => [
                    'id' => $l->id,
                    'nom' => $l->employe?->nom_complet,
                    'net' => (float) $l->net,
                    'deja_paye' => (float) $l->deja_paye,
                    'reste_a_payer' => (float) $l->reste_a_payer,
                    'statut' => $l->statut?->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Lignes de paie du mois, ou null si la Comptabilité n'a encore rien repris (estimation RH).
     *
     * @return Collection<int, PaieLigne>|null
     */
    private function lignesDuMois(string $organizationId, int $annee, int $mois): ?Collection
    {
        $periode = PaiePeriode::where('organization_id', $organizationId)
            ->where('annee', $annee)->where('mois', $mois)
            ->first();

        $lignes = $periode !== null
            ? PaieLigne::where('paie_periode_id', $periode->id)->with(['employe:id,site_id,personne_id', 'employe.personne'])->get()
            : collect();

        return $lignes->isEmpty() ? null : $lignes;
    }
}
