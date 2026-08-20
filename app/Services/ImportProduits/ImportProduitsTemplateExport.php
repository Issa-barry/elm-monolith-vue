<?php

namespace App\Services\ImportProduits;

use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\ProduitType;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Modèle Excel téléchargeable pour l'import produits — 4 onglets générés dynamiquement pour
 * l'organisation connectée (MODE_EMPLOI, PRODUITS, REFERENCES, EXEMPLES), cf.
 * ImportProduitsController::template().
 */
class ImportProduitsTemplateExport implements WithMultipleSheets
{
    public function __construct(private readonly string $orgId) {}

    public function sheets(): array
    {
        $typesActifs = ProduitType::where('organization_id', $this->orgId)
            ->where('statut', 'actif')
            ->orderBy('position')->orderBy('nom')
            ->get();
        $categories = Categorie::where('organization_id', $this->orgId)->orderBy('nom')->get();
        $fournisseurs = Fournisseur::where('organization_id', $this->orgId)->actifs()->orderBy('reference')->get();

        return [
            new ImportProduitsModeEmploiSheetExport,
            new ImportProduitsProduitsSheetExport($typesActifs),
            new ImportProduitsReferencesSheetExport($typesActifs, $categories, $fournisseurs),
            new ImportProduitsExemplesSheetExport,
        ];
    }
}
