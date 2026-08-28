<?php

namespace App\Services\ImportProduits;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Feuille "EXEMPLES" — purement illustrative, jamais importée (seul l'onglet PRODUITS l'est,
 * cf. ImportProduitsParser::lireFeuille()). La colonne `note` (absente du modèle réel) explique
 * chaque cas ; les colonnes suivantes reproduisent exactement celles de l'onglet PRODUITS.
 */
class ImportProduitsExemplesSheetExport implements FromArray, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'EXEMPLES';
    }

    public function headings(): array
    {
        return ['note (colonne absente du modèle réel — jamais importée)', ...ImportProduitsProduitsSheetExport::COLONNES];
    }

    public function array(): array
    {
        // Chaque ligne : [note, sku, nom, type_code, categorie_reference, fournisseur_reference,
        // statut, code_barres, prix_achat, prix_usine_autres_vehicules, prix_usine_tricycle,
        // prix_vente, prix_externe, prix_revendeur, prix_distributeur, cout,
        // alerte_stock_active, seuil_alerte_stock, description] — 18 colonnes après la note,
        // dans l'ordre exact d'ImportProduitsProduitsSheetExport::COLONNES.
        return [
            ['Création sans SKU (généré automatiquement)', '', 'Sel Alpha 1kg', 'achat_vente', '', '', 'actif', '', '1000', '', '', '1500', '', '', '', '', 'non', '', ''],
            ['Création avec SKU fourni explicitement (nécessaire pour un futur réimport de mise à jour)', 'IMPORT-0001', 'Sel Beta 1kg', 'achat_vente', '', '', 'actif', '', '1000', '', '', '1500', '', '', '', '', 'non', '', ''],
            ["Mise à jour d'un prix — SKU déjà existant, les autres cellules vides conservent leur valeur actuelle", 'IMPORT-0001', '', '', '', '', '', '', '', '', '', '1600', '', '', '', '', '', '', ''],
            ['Suppression volontaire de la catégorie avec #VIDER#', 'IMPORT-0001', '', '', '#VIDER#', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ["Ligne inchangée : toutes les valeurs renseignées sont identiques à l'existant — aucune écriture", 'IMPORT-0001', 'Sel Beta 1kg', '', '', '', '', '', '', '', '', '1600', '', '', '', '', 'non', '', ''],
            ['Création fabricable — prix_externe/prix_revendeur/prix_distributeur obligatoires pour ce type', 'IMPORT-0002', 'Bidon 20L Fabricable', 'fabricable', '', '', 'actif', '', '', '18000', '18000', '20000', '18250', '19000', '18500', '', 'non', '', ''],
        ];
    }
}
