<?php

namespace App\Services\ImportProduits;

use App\Enums\ProduitStatut;
use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\ProduitType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Feuille "REFERENCES" — lecture seule, jamais importée. Restitue exclusivement les données de
 * l'organisation connectée (types actifs, catégories, fournisseurs actifs) : ne fuite jamais
 * vers une autre organisation, cf. ImportProduitsController::template()/reprise().
 */
class ImportProduitsReferencesSheetExport implements FromArray, WithEvents, WithTitle
{
    /**
     * @param  Collection<int, ProduitType>  $typesActifs
     * @param  Collection<int, Categorie>  $categories
     * @param  Collection<int, Fournisseur>  $fournisseurs
     */
    public function __construct(
        private readonly Collection $typesActifs,
        private readonly Collection $categories,
        private readonly Collection $fournisseurs,
    ) {}

    public function title(): string
    {
        return 'REFERENCES';
    }

    public function array(): array
    {
        $lignes = [];

        $lignes[] = ['TYPES ACTIFS'];
        $lignes[] = ['type_code', 'nom', 'gere_stock', 'vendable', 'achetable', 'prix_achat_requis', 'prix_usine_requis', 'prix_vente_requis', 'champ_prix_reference'];
        foreach ($this->typesActifs as $t) {
            $lignes[] = [
                $t->code,
                ImportProduitsCellSanitizer::neutraliser($t->nom),
                $t->gere_stock ? 'oui' : 'non',
                $t->vendable ? 'oui' : 'non',
                $t->achetable ? 'oui' : 'non',
                $t->prix_achat_requis ? 'oui' : 'non',
                $t->prix_usine_requis ? 'oui' : 'non',
                $t->prix_vente_requis ? 'oui' : 'non',
                $t->champ_prix_reference ?? '',
            ];
        }

        $lignes[] = [];
        $lignes[] = ['CATEGORIES'];
        $lignes[] = ['categorie_reference', 'nom'];
        foreach ($this->categories as $c) {
            $lignes[] = [$c->reference, ImportProduitsCellSanitizer::neutraliser($c->nom)];
        }

        $lignes[] = [];
        $lignes[] = ['FOURNISSEURS ACTIFS'];
        $lignes[] = ['fournisseur_reference', 'nom'];
        foreach ($this->fournisseurs as $f) {
            $lignes[] = [$f->reference, ImportProduitsCellSanitizer::neutraliser($f->nom_complet ?? $f->reference)];
        }

        $lignes[] = [];
        $lignes[] = ['STATUTS ACCEPTES (colonne statut)'];
        foreach (ProduitStatut::cases() as $s) {
            $lignes[] = [$s->value, $s->label()];
        }

        $lignes[] = [];
        $lignes[] = ['VALEURS ACCEPTEES (colonne alerte_stock_active)'];
        $lignes[] = ['oui', 'non'];

        $lignes[] = [];
        $lignes[] = ['COLONNES DE TARIFICATION USINE'];
        $lignes[] = ['prix_usine_autres_vehicules', 'Prix usine — Autres véhicules (GNF)'];
        $lignes[] = ['prix_usine_tricycle', 'Prix usine — Tricycle (GNF)'];

        $lignes[] = [];
        $lignes[] = ['COLONNES DE TARIFICATION PAR NATURE DE CLIENT — obligatoires pour le type fabricable'];
        $lignes[] = ['prix_externe', 'Prix appliqué à un client Externe (GNF)'];
        $lignes[] = ['prix_revendeur', 'Prix appliqué à un client Revendeur (GNF)'];
        $lignes[] = ['prix_distributeur', 'Prix appliqué à un client Distributeur (GNF)'];
        $lignes[] = ['(facultatif pour les autres types)', 'Peut rester vide en dehors du type fabricable'];

        $lignes[] = [];
        $lignes[] = ['CONVENTION #VIDER# (colonnes facultatives uniquement)'];
        $lignes[] = ['Cellule vide', 'Conserver la valeur existante (mise à jour) / valeur par défaut (création)'];
        $lignes[] = ['Valeur renseignée', 'Remplacer la valeur existante'];
        $lignes[] = ['#VIDER#', 'Effacer explicitement la valeur — refusé sur un champ obligatoire ou à la création'];

        return $lignes;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
                    $event->sheet->getDelegate()->getColumnDimension($col)->setWidth(22);
                }
                $event->sheet->getDelegate()->getColumnDimension('A')->setWidth(34);
                $event->sheet->getDelegate()->getColumnDimension('B')->setWidth(42);
            },
        ];
    }
}
