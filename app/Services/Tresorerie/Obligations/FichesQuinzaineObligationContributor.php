<?php

namespace App\Services\Tresorerie\Obligations;

use App\Enums\TypePeriodePaiement;
use App\Models\PaiementFiche;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Carbon\CarbonInterface;

/**
 * Fiches payées toutes les deux semaines — DEUX colonnes mensuelles (P1 et P2, jamais cumulées),
 * contrairement aux propriétaires/salariés (mensuel). Livreurs et, depuis l'ADR 0035, parrains de
 * véhicule (même rythme, même agence que le livreur dont ils partagent l'enveloppe).
 */
abstract class FichesQuinzaineObligationContributor implements ObligationContributor
{
    public function __construct(
        private readonly PeriodePaiementService $periodePaiementService,
        private readonly PeriodeCalculatorService $periodeCalculatorService,
    ) {}

    /** Type de période et `beneficiaire_type` des fiches (même valeur). */
    abstract protected function type(): TypePeriodePaiement;

    /** Préfixe des colonnes : « {prefixe}_p1 », « {prefixe}_p2 ». */
    abstract protected function prefixe(): string;

    public function colonnes(): array
    {
        return [$this->colonne('p1'), $this->colonne('p2')];
    }

    public function echeancesParColonne(): array
    {
        return [$this->colonne('p1') => 'quinzaine_1', $this->colonne('p2') => 'fin_de_mois'];
    }

    public function collecter(string $organizationId, int $annee, int $mois, array &$besoin): void
    {
        $colonnes = [
            PeriodePaiementService::P1 => $this->colonne('p1'),
            PeriodePaiementService::P2 => $this->colonne('p2'),
        ];

        foreach ($colonnes as $quinzaine => $colonne) {
            $periode = $this->periode($organizationId, $annee, $mois, $quinzaine);

            PaiementFiche::where('organization_id', $organizationId)
                ->where('periode_id', $periode->id)
                ->where('beneficiaire_type', $this->type()->value)
                ->get(['site_id', 'montant_net', 'montant_paye'])
                ->each(function (PaiementFiche $fiche) use (&$besoin, $colonne) {
                    ObligationAccumulator::ajouter($besoin, $fiche->site_id, $colonne, $fiche->montant_restant, (float) $fiche->montant_net);
                });
        }
    }

    public function arrieres(string $organizationId, CarbonInterface $debutMois, array &$arrieres): void
    {
        PaiementFiche::where('organization_id', $organizationId)
            ->where('beneficiaire_type', $this->type()->value)
            ->whereColumn('montant_paye', '<', 'montant_net')
            ->whereHas('periode', fn ($q) => $q->whereDate('date_fin', '<', $debutMois->toDateString()))
            ->get(['site_id', 'montant_net', 'montant_paye'])
            ->each(function (PaiementFiche $fiche) use (&$arrieres) {
                ObligationAccumulator::ajouterArriere($arrieres, $fiche->site_id, $fiche->montant_restant);
            });
    }

    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        return [
            $this->colonne('p1') => $this->detailQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P1, $siteId),
            $this->colonne('p2') => $this->detailQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P2, $siteId),
        ];
    }

    private function colonne(string $quinzaine): string
    {
        return "{$this->prefixe()}_{$quinzaine}";
    }

    /** @return list<array<string, mixed>> */
    private function detailQuinzaine(string $organizationId, int $annee, int $mois, string $quinzaine, ?string $siteId): array
    {
        $periode = $this->periode($organizationId, $annee, $mois, $quinzaine);

        return PaiementFiche::where('organization_id', $organizationId)
            ->where('periode_id', $periode->id)
            ->where('beneficiaire_type', $this->type()->value)
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
        $periode = $this->periodePaiementService->getOrCreatePeriod($organizationId, $this->type(), $debut);
        $this->periodeCalculatorService->calculerSiNecessaire($periode);

        return $periode;
    }
}
