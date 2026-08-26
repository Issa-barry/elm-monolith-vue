<?php

namespace App\Services\Tresorerie\Obligations;

use App\Enums\TypePeriodePaiement;
use App\Models\PaiementFiche;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;

/**
 * Commissions propriétaires — une seule colonne mensuelle : les deux
 * quinzaines sont cumulées (jamais affichées séparément comme les livreurs),
 * sans double comptage puisque chacune couvre une plage de dates disjointe.
 */
class ProprietaireObligationContributor implements ObligationContributor
{
    public function __construct(
        private readonly PeriodePaiementService $periodePaiementService,
        private readonly PeriodeCalculatorService $periodeCalculatorService,
    ) {}

    public function colonnes(): array
    {
        return ['proprietaires'];
    }

    public function echeancesParColonne(): array
    {
        return ['proprietaires' => 'fin_de_mois'];
    }

    public function collecter(string $organizationId, int $annee, int $mois, array &$besoin): void
    {
        foreach ([PeriodePaiementService::P1, PeriodePaiementService::P2] as $quinzaine) {
            $periode = $this->periode($organizationId, $annee, $mois, $quinzaine);

            PaiementFiche::where('organization_id', $organizationId)
                ->where('periode_id', $periode->id)
                ->where('beneficiaire_type', 'proprietaire')
                ->get(['site_id', 'montant_net', 'montant_paye'])
                ->each(function (PaiementFiche $fiche) use (&$besoin) {
                    ObligationAccumulator::ajouter($besoin, $fiche->site_id, 'proprietaires', $fiche->montant_restant, (float) $fiche->montant_net);
                });
        }
    }

    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        return [
            'proprietaires' => [
                ...$this->detailQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P1, $siteId),
                ...$this->detailQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P2, $siteId),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function detailQuinzaine(string $organizationId, int $annee, int $mois, string $quinzaine, ?string $siteId): array
    {
        $periode = $this->periode($organizationId, $annee, $mois, $quinzaine);

        return PaiementFiche::where('organization_id', $organizationId)
            ->where('periode_id', $periode->id)
            ->where('beneficiaire_type', 'proprietaire')
            ->where('site_id', $siteId)
            ->get()
            ->filter(fn (PaiementFiche $f) => $f->montant_restant > 0.0)
            ->map(fn (PaiementFiche $f) => [
                'id' => $f->id,
                'nom' => $f->beneficiaire_nom,
                'quinzaine' => $quinzaine,
                'montant_net' => (float) $f->montant_net,
                'montant_paye' => (float) $f->montant_paye,
                'montant_restant' => $f->montant_restant,
                'statut' => $f->statut?->value,
                'statut_label' => $f->statut_label,
            ])
            ->values()
            ->all();
    }

    private function periode(string $organizationId, int $annee, int $mois, string $quinzaine)
    {
        [$debut] = PeriodePaiementService::dateRangeFor($annee, $mois, $quinzaine);
        $periode = $this->periodePaiementService->getOrCreatePeriod($organizationId, TypePeriodePaiement::PROPRIETAIRE, $debut);
        $this->periodeCalculatorService->calculerSiNecessaire($periode);

        return $periode;
    }
}
