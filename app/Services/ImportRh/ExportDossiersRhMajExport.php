<?php

namespace App\Services\ImportRh;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\ServiceRh;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Fichier « Exporter pour mise à jour » des dossiers RH : feuille « employes » pré-remplie (la
 * seule importée), « references » et « mode_emploi ». La colonne salaire n'existe que si
 * l'utilisateur a `rh-remunerations.read`.
 */
class ExportDossiersRhMajExport implements WithMultipleSheets
{
    /**
     * @param  Collection<int, Employe>  $employes  chargés avec personne, site, service, fonction, contratActif
     * @param  Collection<int, ServiceRh>  $services  actifs
     * @param  Collection<int, FonctionRh>  $fonctions  actives
     */
    public function __construct(
        private readonly Collection $employes,
        private readonly Collection $services,
        private readonly Collection $fonctions,
        private readonly bool $avecSalaire,
    ) {}

    public function sheets(): array
    {
        return [
            new ImportRhEmployesSheetExport($this->employes, $this->avecSalaire),
            new ImportRhReferencesSheetExport($this->services, $this->fonctions),
            new ImportRhModeEmploiSheetExport($this->avecSalaire),
        ];
    }
}
