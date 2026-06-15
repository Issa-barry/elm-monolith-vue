<?php

namespace App\Enums;

enum CategorieDepense: string
{
    case VEHICULE = 'vehicule';
    case PROPRIETAIRE = 'proprietaire';
    case LIVREUR = 'livreur';
    case EMPLOYE = 'employe';
    case INTERNE = 'interne';

    public function label(): string
    {
        return match ($this) {
            self::VEHICULE => 'Véhicule',
            self::PROPRIETAIRE => 'Propriétaire',
            self::LIVREUR => 'Livreur',
            self::EMPLOYE => 'Salarié',
            self::INTERNE => 'Interne',
        };
    }

    public function labelConcerne(): string
    {
        return match ($this) {
            self::VEHICULE => 'Véhicule',
            self::PROPRIETAIRE => 'Propriétaire',
            self::LIVREUR => 'Livreur',
            self::EMPLOYE => 'Salarié',
            self::INTERNE => 'Dépense interne',
        };
    }

    public function impactMessage(): string
    {
        return match ($this) {
            self::VEHICULE => 'Cette dépense sera déduite de la commission du propriétaire du véhicule sélectionné.',
            self::PROPRIETAIRE => 'Cette dépense sera déduite de la commission mensuelle du propriétaire sélectionné.',
            self::LIVREUR => 'Cette dépense sera déduite de la commission quinzaine du livreur sélectionné.',
            self::EMPLOYE => 'Cette dépense sera déduite du salaire mensuel du salarié sélectionné.',
            self::INTERNE => 'Aucune retenue ne sera générée. Cette dépense est interne à l\'agence.',
        };
    }

    public function needsBeneficiaire(): bool
    {
        return $this !== self::INTERNE;
    }

    public function beneficiaireTable(): ?string
    {
        return match ($this) {
            self::INTERNE => null,
            self::EMPLOYE => 'employes',
            self::LIVREUR => 'livreurs',
            self::PROPRIETAIRE => 'proprietaires',
            self::VEHICULE => 'vehicules',
        };
    }

    public function imputationType(): ?string
    {
        return match ($this) {
            self::INTERNE => null,
            self::EMPLOYE => 'salaire',
            self::LIVREUR => 'commission_livreur',
            self::PROPRIETAIRE => 'commission_proprietaire',
            self::VEHICULE => 'commission_proprietaire',
        };
    }

    public function periodeType(): string
    {
        return $this === self::LIVREUR ? 'quinzaine' : 'mensuelle';
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }

    public static function optionsConcerne(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->labelConcerne()],
            self::cases(),
        );
    }
}
