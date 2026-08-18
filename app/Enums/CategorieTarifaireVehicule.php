<?php

namespace App\Enums;

/**
 * Classification commerciale d'un type de véhicule pour la résolution du prix usine applicable
 * (cf. PrixUsineResolver) — indépendante de la classification libre `TypeVehicule.nom` : ne
 * jamais dériver cette distinction d'une comparaison sur le nom affiché (renommable), toujours
 * de ce champ structuré.
 *
 * TRICYCLE : véhicules légers, tarif usine réduit (cf. produit_variantes.prix_usine_tricycle).
 * AUTRE_VEHICULE : tout le reste (camion, fourgon, voiture...) — tarif usine standard
 * (produit_variantes.prix_usine). Valeur par défaut implicite quand le type de véhicule n'a
 * pas encore été classé (jamais bloquant, cf. migration).
 */
enum CategorieTarifaireVehicule: string
{
    case TRICYCLE = 'tricycle';
    case AUTRE_VEHICULE = 'autre_vehicule';

    public function label(): string
    {
        return match ($this) {
            self::TRICYCLE => 'Tricycle',
            self::AUTRE_VEHICULE => 'Autre véhicule',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
