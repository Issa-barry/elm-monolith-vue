<?php

namespace App\Services;

use App\Enums\CategorieDepense;
use App\Models\Depense;
use App\Models\DepenseImputation;
use App\Models\Vehicule;
use Carbon\Carbon;

class DepenseImputationService
{
    public function creer(Depense $depense): ?DepenseImputation
    {
        $categorie = $depense->depenseType->categorie;

        if ($categorie === CategorieDepense::INTERNE) {
            return null;
        }

        [$imputationType, $beneficiaireType, $beneficiaireId] = $this->resoudreBeneficiaire($depense, $categorie);

        $periodeType = $categorie->periodeType();
        [$periodeDebut, $periodeFin] = $this->calculerPeriode($depense->date_depense, $periodeType);

        return DepenseImputation::create([
            'depense_id' => $depense->id,
            'imputation_type' => $imputationType,
            'beneficiaire_type' => $beneficiaireType,
            'beneficiaire_id' => $beneficiaireId,
            'montant' => $depense->montant,
            'periode_type' => $periodeType,
            'periode_debut' => $periodeDebut,
            'periode_fin' => $periodeFin,
            'statut' => 'impute',
        ]);
    }

    private function resoudreBeneficiaire(Depense $depense, CategorieDepense $categorie): array
    {
        if ($categorie === CategorieDepense::VEHICULE) {
            $vehicule = Vehicule::findOrFail($depense->beneficiaire_id);

            if (! $vehicule->proprietaire_id) {
                throw new \RuntimeException(
                    "Le véhicule « {$vehicule->nom_vehicule} » n'a pas de propriétaire enregistré. Impossible d'imputer la dépense."
                );
            }

            return ['commission_proprietaire', 'proprietaire', $vehicule->proprietaire_id];
        }

        return [
            $categorie->imputationType(),
            $depense->beneficiaire_type,
            $depense->beneficiaire_id,
        ];
    }

    private function calculerPeriode(Carbon $date, string $periodeType): array
    {
        if ($periodeType === 'quinzaine') {
            if ($date->day <= 15) {
                return [
                    $date->copy()->startOfMonth()->toDateString(),
                    $date->copy()->setDay(15)->toDateString(),
                ];
            }

            return [
                $date->copy()->setDay(16)->toDateString(),
                $date->copy()->endOfMonth()->toDateString(),
            ];
        }

        return [
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
        ];
    }
}
