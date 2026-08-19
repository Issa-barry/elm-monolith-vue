<?php

namespace App\Services\ImportFlotte;

use App\Models\Categorie;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Gabarit "vehicules" propre à une organisation : une colonne "capacite__<REFERENCE>" par
 * Categorie de son catalogue (cf. ImportFlotteParser::resoudreColonnesCapacite()), plutôt que
 * les deux colonnes fixes "sachets"/"bouteilles" d'avant — chaque organisation plafonne les
 * catégories de son choix, en nombre libre.
 */
class ImportFlotteVehiculesSheetExport implements FromArray, WithHeadings, WithTitle
{
    /** @param  Collection<int, Categorie>  $categories catégories de l'organisation (toutes, pas seulement celles déjà utilisées comme capacité), triées par nom pour un ordre stable. */
    public function __construct(private readonly Collection $categories = new Collection) {}

    public function title(): string
    {
        return 'vehicules';
    }

    public function headings(): array
    {
        return [
            'vehicule_site',
            'vehicule_nom',
            'vehicule_immatriculation',
            'vehicule_type',
            'vehicule_categorie',
            ...$this->colonnesCapacite(),
            'vehicule_livraison_vente',
            'vehicule_livraison_logistique',
            'proprietaire_nom',
            'proprietaire_prenom',
            'proprietaire_telephone',
            'proprietaire_pays',
        ];
    }

    public function array(): array
    {
        // Une seule ligne par véhicule. Chaque colonne "capacite__<REFERENCE>" est facultative :
        // laissée vide, le véhicule reste non plafonné pour cette catégorie — aucune capacité
        // n'est portée par le type de véhicule (cf. ImportFlotteParser). La commission d'équipe
        // se configure après coup dans Équipes de livraison.
        //
        // vehicule_site est obligatoire pour tout véhicule, quel que soit son propriétaire.
        // vehicule_categorie : interne ou partenaire, obligatoire sur chaque ligne (même une
        // ligne d'ancrage pour un véhicule déjà existant) — jamais devinée silencieusement, cf.
        // ImportFlotteParser. "partenaire" exige les colonnes proprietaire_* renseignées ;
        // "interne" exige qu'elles restent vides.
        // vehicule_livraison_vente / vehicule_livraison_logistique : oui/non (yes/no, 1/0,
        // true/false acceptés) — une cellule vide vaut "non" (aucun usage par défaut,
        // jamais un usage vente implicite), cf. ImportFlotteParser::toUsageBool(). Un
        // véhicule sans aucun des deux reste importé mais non exploitable tant qu'un usage
        // n'est pas défini (cf. Vehicule::aAuMoinsUnUsage()).
        $nbColonnesCapacite = count($this->colonnesCapacite());
        // Exemple de valeur uniquement sur la toute première colonne de capacité (si
        // l'organisation en a au moins une) — les autres restent vides, comme n'importe quelle
        // capacité non renseignée. array_pad ne tronque jamais : géré à part pour 0 colonne.
        $exempleCapacites = $nbColonnesCapacite > 0 ? array_pad(['80'], $nbColonnesCapacite, '') : [];

        return [
            ['Matoto', 'Camion 1', 'RC-1234-A', 'Tricycle', 'interne', ...$exempleCapacites, 'oui', 'non', '', '', '', ''],
            ['Matoto', 'Camion 2', 'RC-5678-B', 'Tricycle', 'partenaire', ...array_fill(0, $nbColonnesCapacite, ''), 'oui', 'non', 'Diallo', 'Mamadou', '622000001', 'GN'],
        ];
    }

    /** @return string[] */
    private function colonnesCapacite(): array
    {
        return $this->categories
            ->map(fn (Categorie $c) => 'capacite__'.$c->reference)
            ->all();
    }
}
