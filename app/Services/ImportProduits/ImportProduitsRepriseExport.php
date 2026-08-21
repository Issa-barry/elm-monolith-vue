<?php

namespace App\Services\ImportProduits;

use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\ImportProduits;
use App\Models\Produit;
use App\Models\ProduitType;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Fichier de reprise téléchargeable après un import TERMINE — régénéré à la demande depuis le
 * rapport JSON persisté (jamais stocké comme fichier séparé, cf.
 * ImportProduitsController::reprise()). Ferme la boucle d'idempotence : réimporter ce fichier
 * sans modification classe toutes les lignes "inchange", le modifier produit des "mise_a_jour"
 * ciblées.
 */
class ImportProduitsRepriseExport implements WithMultipleSheets
{
    public function __construct(private readonly ImportProduits $import) {}

    public function sheets(): array
    {
        $lignes = collect($this->import->rapport['lignes'] ?? []);
        $produitIds = $lignes->pluck('produit_id')->filter()->unique()->values()->all();

        $produitsParId = Produit::with(['variantes', 'categorie', 'fournisseur', 'produitType'])
            ->whereIn('id', $produitIds)
            ->get()
            ->keyBy('id');

        $orgId = $this->import->organization_id;
        $typesActifs = ProduitType::where('organization_id', $orgId)->where('statut', 'actif')->orderBy('position')->orderBy('nom')->get();
        $categories = Categorie::where('organization_id', $orgId)->orderBy('nom')->get();
        $fournisseurs = Fournisseur::where('organization_id', $orgId)->actifs()->orderBy('reference')->get();

        return [
            new ImportProduitsModeEmploiSheetExport,
            new ImportProduitsRepriseProduitsSheetExport($lignes, $produitsParId),
            new ImportProduitsReferencesSheetExport($typesActifs, $categories, $fournisseurs),
            new ImportProduitsRepriseResultatsSheetExport($lignes),
        ];
    }
}
