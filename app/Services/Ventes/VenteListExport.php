<?php

namespace App\Services\Ventes;

use App\Models\CommandeVente;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export "Exporter" de Ventes/Index.vue (ExportCommandeVenteController) — mêmes données que la
 * page (cf. IndexCommandeVenteController::mapCommandeForIndex), colonnes sélectionnables.
 * "Agence" est le seul intitulé retenu pour le site : il n'existe aucune entité Agence distincte
 * de Site dans ce domaine (cf. DataFilters.vue, qui affiche déjà le sélecteur de site sous ce nom).
 */
class VenteListExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    /** @var array<string, string> */
    public const COLUMNS = [
        'reference' => 'Référence',
        'date' => 'Date',
        'client' => 'Client',
        'vehicule' => 'Véhicule',
        'livreur' => 'Livreur',
        'agence' => 'Agence',
        'processus' => 'Processus',
        'montant' => 'Montant',
        'deja_paye' => 'Déjà payé',
        'reste' => 'Reste à encaisser',
        'statut' => 'Statut',
    ];

    /**
     * @param  Collection<int, CommandeVente>  $commandes  chargées avec ['vehicule.equipe.livreurs', 'client', 'site', 'facture']
     * @param  list<string>  $columns  sous-ensemble de array_keys(self::COLUMNS) ; vide = toutes les colonnes
     */
    public function __construct(
        private readonly Collection $commandes,
        private readonly array $columns,
        private readonly string $sheetTitle = 'ventes',
    ) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function collection(): Collection
    {
        return $this->commandes;
    }

    /** @return list<string> */
    private function activeColumns(): array
    {
        return $this->columns === []
            ? array_keys(self::COLUMNS)
            : array_values(array_intersect(array_keys(self::COLUMNS), $this->columns));
    }

    public function headings(): array
    {
        return array_map(fn (string $key) => self::COLUMNS[$key], $this->activeColumns());
    }

    public function map($commande): array
    {
        /** @var CommandeVente $commande */
        $chauffeur = $commande->vehicule?->equipe?->livreurs
            ?->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur');

        $processusCode = CommissionProcessusDefaults::identiteCodePourVente(
            $commande->nature_operation,
            $commande->client?->type,
            $commande->mode_remise_grossiste,
        );

        $values = [
            'reference' => $commande->reference,
            'date' => $commande->created_at?->format('d/m/Y') ?? '',
            'client' => $commande->client?->nom_complet ?? '',
            'vehicule' => $commande->vehicule?->nom_vehicule ?? '',
            'livreur' => $chauffeur?->nom_complet ?? '',
            'agence' => $commande->site?->nom ?? '',
            'processus' => CommissionProcessusDefaults::libelle($processusCode),
            'montant' => (float) $commande->total_commande,
            'deja_paye' => $commande->facture ? (float) $commande->facture->montant_encaisse : 0.0,
            'reste' => $commande->facture ? (float) $commande->facture->montant_restant : 0.0,
            'statut' => $commande->statutAffichage()['label'],
        ];

        return array_map(fn (string $key) => $values[$key], $this->activeColumns());
    }
}
