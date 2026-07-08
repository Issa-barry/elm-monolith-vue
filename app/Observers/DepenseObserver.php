<?php

namespace App\Observers;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\JournalTresorerie;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Services\JournalTresorerieService;
use App\Services\PaieCalculService;
use App\Services\PeriodeCalculatorService;

class DepenseObserver
{
    public function __construct(
        private PaieCalculService $paieCalc,
        private PeriodeCalculatorService $periodeCalculator,
    ) {}

    public function updated(Depense $depense): void
    {
        if (! $depense->wasChanged('statut')) {
            return;
        }

        $wasValide = $depense->getOriginal('statut') === StatutDepense::VALIDE;
        $isValide = $depense->statut === StatutDepense::VALIDE;

        if (! $wasValide && ! $isValide) {
            return;
        }

        match ($depense->beneficiaire_type) {
            'employe' => $this->syncPaieLigne($depense),
            // Une dépense livreur/propriétaire/véhicule (dé)validée modifie le net à payer d'une
            // période de paiement déjà calculée (cf. PeriodeCalculatorService::signatureSource) :
            // on la recalcule immédiatement plutôt que de laisser des montants obsolètes affichés
            // jusqu'à la prochaine ouverture de la page.
            'livreur', 'proprietaire', 'vehicule' => $this->recalculerPeriodePaiement($depense),
            null => $this->syncJournalDepenseInterne($depense, $isValide),
            default => null,
        };
    }

    private function recalculerPeriodePaiement(Depense $depense): void
    {
        $this->periodeCalculator->recalculerPeriodesConcernees($depense->organization_id, $depense->date_depense);
    }

    private function syncPaieLigne(Depense $depense): void
    {
        $date = $depense->date_depense;

        $periode = PaiePeriode::where('organization_id', $depense->organization_id)
            ->where('mois', (int) $date->format('m'))
            ->where('annee', (int) $date->format('Y'))
            ->first();

        if (! $periode) {
            return;
        }

        $ligne = PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $depense->beneficiaire_id)
            ->first();

        if (! $ligne) {
            return;
        }

        $this->paieCalc->importerDepenses($ligne, $periode);
        $ligne->load('variables');
        $this->paieCalc->calculerLigne($ligne);
    }

    public function deleted(Depense $depense): void
    {
        JournalTresorerie::where('source_type', Depense::class)
            ->where('source_id', $depense->id)
            ->delete();
    }

    private function syncJournalDepenseInterne(Depense $depense, bool $isValide): void
    {
        JournalTresorerie::where('source_type', Depense::class)
            ->where('source_id', $depense->id)
            ->delete();

        if ($isValide) {
            $depense->loadMissing('depenseType');
            JournalTresorerieService::enregistrerDepenseInterne($depense);
        }
    }
}
