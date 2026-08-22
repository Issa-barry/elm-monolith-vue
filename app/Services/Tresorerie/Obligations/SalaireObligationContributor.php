<?php

namespace App\Services\Tresorerie\Obligations;

use App\Models\PaieLigne;
use App\Services\PaieCalculService;

/**
 * Salaires — mensuel, sourcé directement sur PaieLigne/PaiePeriode (jamais via
 * PaiementPeriode/PaiementFiche comme livreurs/propriétaires) : bien que
 * TypePeriodePaiement::periodicity() vaille "quinzaine" pour SALARIE aussi,
 * PeriodeCalculatorService::calculerSalaries() sélectionne les PaieLigne par
 * MOIS entier (whereMonth) — calculer P1 et P2 "salarié" séparément via ce
 * chemin dupliquerait le salaire mensuel complet sur chacune. PaieLigne (un
 * employé x un mois, contrainte unique) n'a pas cette ambiguïté.
 */
class SalaireObligationContributor implements ObligationContributor
{
    public function __construct(
        private readonly PaieCalculService $paieCalculService,
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
        $periode = $this->paieCalculService->getOrGenererPeriode($organizationId, $mois, $annee);

        PaieLigne::where('paie_periode_id', $periode->id)
            ->with('employe:id,site_id')
            ->get(['id', 'employe_id', 'net', 'reste_a_payer'])
            ->each(function (PaieLigne $ligne) use (&$besoin) {
                ObligationAccumulator::ajouter($besoin, $ligne->employe?->site_id, 'salaires', (float) $ligne->reste_a_payer, (float) $ligne->net);
            });
    }

    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        $periode = $this->paieCalculService->getOrGenererPeriode($organizationId, $mois, $annee);

        return [
            'salaires' => PaieLigne::where('paie_periode_id', $periode->id)
                ->whereHas('employe', fn ($q) => $q->where('site_id', $siteId))
                ->with(['employe:id,personne_id', 'employe.personne'])
                ->get()
                ->filter(fn (PaieLigne $l) => (float) $l->reste_a_payer > 0.0)
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
}
