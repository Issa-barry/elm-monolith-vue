<?php

namespace App\Services\DepenseTypes;

use App\Models\DepenseType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export .xlsx de la liste des types de dépense — reprend exactement les
 * colonnes affichées dans Depenses/Types/Index.vue et respecte les filtres
 * actifs (la collection est déjà filtrée par DepenseTypeController).
 */
class DepenseTypeListExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    /** @param  Collection<int, DepenseType>  $types */
    public function __construct(private readonly Collection $types) {}

    public function title(): string
    {
        return 'types-depense';
    }

    public function collection(): Collection
    {
        return $this->types;
    }

    public function headings(): array
    {
        return ['Libellé', 'Concerné', 'Commentaire requis', 'Justificatif requis', 'Statut'];
    }

    public function map($type): array
    {
        return [
            $type->libelle,
            $type->categorie->label(),
            $type->commentaire_obligatoire ? 'Oui' : 'Non',
            $type->justificatif_obligatoire ? 'Oui' : 'Non',
            $type->is_active ? 'Actif' : 'Inactif',
        ];
    }
}
