<?php

namespace App\Services\ImportProduits;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Feuille "MODE_EMPLOI" — première feuille du classeur (modèle ET fichier de reprise), jamais
 * importée. Résume la convention à 3 états et les règles de création/mise à jour — le détail
 * exact (obligatoire/facultatif par colonne) reste porté par ImportProduitsParser, seule source
 * de vérité ; ce texte est volontairement une vue d'ensemble, pas une duplication exhaustive.
 */
class ImportProduitsModeEmploiSheetExport implements FromArray, WithEvents, WithTitle
{
    public function title(): string
    {
        return 'MODE_EMPLOI';
    }

    public function array(): array
    {
        return [
            ['Import Produits — mode d\'emploi'],
            [],
            ["Seul l'onglet PRODUITS est importé. REFERENCES et EXEMPLES sont uniquement informatifs."],
            [],
            ['Identification (colonne sku)'],
            ['  SKU vide → création du produit (un SKU est généré automatiquement).'],
            ['  SKU déjà existant (variante par défaut d\'un produit simple) → mise à jour de ce produit.'],
            ['  SKU inconnu → création du produit avec ce SKU explicite (utile pour préparer un futur réimport).'],
            ['  Le SKU ne peut jamais être modifié une fois le produit créé.'],
            ['  Le nom n\'est jamais utilisé pour identifier un produit existant.'],
            [],
            ['Colonnes obligatoires à la CRÉATION uniquement'],
            ['  nom, type_code, statut, alerte_stock_active — ignorées (conservées) si vides en mise à jour.'],
            ['  Le type (type_code) peut être changé en mise à jour : les prix effectifs sont alors revalidés selon les règles du nouveau type.'],
            [],
            ['Colonnes de tarification usine'],
            ['  prix_usine_autres_vehicules : prix usine appliqué aux véhicules autres que les tricycles (champ « Prix usine — Autres véhicules » dans ELM).'],
            ['  prix_usine_tricycle         : prix usine appliqué aux tricycles (champ « Prix usine — Tricycle » dans ELM).'],
            ['  Ces deux colonnes sont distinctes et doivent être renseignées selon les exigences du type de produit.'],
            [],
            ['Convention à 3 états — colonnes facultatives uniquement'],
            ['  Cellule vide       : conserver la valeur existante (mise à jour) / valeur par défaut (création).'],
            ['  Valeur renseignée  : remplace la valeur existante.'],
            ['  #VIDER#            : efface explicitement la valeur (refusé à la création et sur les champs obligatoires).'],
            [],
            ['Limites'],
            ['  500 lignes maximum par fichier.'],
            ['  Produits à plusieurs déclinaisons (variantes) : non pris en charge par cet import.'],
            [],
            ["Consultez l'onglet REFERENCES pour les codes de type, références de catégorie/fournisseur et valeurs acceptées."],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $event->sheet->getDelegate()->getColumnDimension('A')->setWidth(100);
                $event->sheet->getDelegate()->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            },
        ];
    }
}
