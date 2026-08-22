<?php

namespace App\Observers;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Services\Comptabilite\DepenseComptabilisationService;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\PaieCalculService;
use App\Services\PeriodeCalculatorService;

class DepenseObserver
{
    public function __construct(
        private PaieCalculService $paieCalc,
        private PeriodeCalculatorService $periodeCalculator,
        private DepenseComptabilisationService $depenseComptabilisation,
        private EcritureComptableService $ecritures,
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
            default => null,
        };

        if ($isValide) {
            $this->comptabiliser($depense);
        } elseif ($wasValide) {
            // Dévalidation : la dépense n'est plus reconnue comme charge/avance réelle —
            // contrepasse la pièce existante plutôt que de la supprimer (règle #29 : jamais
            // de suppression destructive d'une écriture validée). Générique à tous les
            // beneficiaire_type via DepenseComptabilisationService::evenementPour().
            $this->decomptabiliser($depense);
        }
    }

    public function deleted(Depense $depense): void
    {
        // Même règle qu'à la dévalidation : on contrepasse, on ne supprime jamais
        // l'écriture. DepenseController::destroy() englobe déjà la suppression dans
        // une transaction.
        $this->decomptabiliser($depense);
    }

    private function comptabiliser(Depense $depense): void
    {
        // Comptabilité générale : une dépense validée décaisse de la trésorerie réelle
        // — bloquant depuis la revue Codex du 2026-08-22 (même raison que
        // PaiementFichePaiement/PaiePaiement). DepenseController::valider() englobe
        // déjà ce changement de statut dans une transaction.
        $this->depenseComptabilisation->comptabiliserDepenseValidee($depense);
    }

    private function decomptabiliser(Depense $depense): void
    {
        $evenement = $this->depenseComptabilisation->evenementPour($depense);
        if (! $evenement) {
            return;
        }

        $piece = $this->ecritures->pieceExistantePour($depense->organization_id, $depense, $evenement);
        if ($piece && $piece->isValidee()) {
            $this->ecritures->contrepasser($piece, 'Dépense dévalidée ou supprimée');
        }
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
}
