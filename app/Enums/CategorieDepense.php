<?php

namespace App\Enums;

enum CategorieDepense: string
{
    case VEHICULE = 'vehicule';
    case PROPRIETAIRE = 'proprietaire';
    case LIVREUR = 'livreur';
    case EMPLOYE = 'employe';
    case INTERNE = 'interne';
    // Valeur alignée sur CommissionEnveloppePart::TYPE_PRESTATAIRE — jamais une chaîne
    // nouvelle/incohérente, cf. décision produit "commission consultant" 2026-08-22. Couvre
    // tout Prestataire (consultant, machiniste, mécanicien...), pas seulement les consultants.
    case PRESTATAIRE = 'prestataire';

    public function label(): string
    {
        return match ($this) {
            self::VEHICULE => 'Véhicule',
            self::PROPRIETAIRE => 'Propriétaire',
            self::LIVREUR => 'Livreur',
            self::EMPLOYE => 'Salarié',
            self::INTERNE => 'Interne',
            self::PRESTATAIRE => 'Prestataire',
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
            self::PRESTATAIRE => 'Prestataire',
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
            self::PRESTATAIRE => 'Cette dépense sera déduite de la commission du prestataire sélectionné, si applicable.',
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
            self::PRESTATAIRE => 'prestataires',
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
            self::PRESTATAIRE => 'commission_prestataire',
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
