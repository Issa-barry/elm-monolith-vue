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
    case CLIENT = 'client';
    // Raccourci vers PRESTATAIRE limité aux prestataires de type consultant : la dépense est
    // enregistrée avec beneficiaire_type=prestataire (cf. beneficiaireType()), donc déduite de la
    // même fiche consultant, sans second chemin.
    case CONSULTANT = 'consultant';
    // Retenue sur la fiche Parrain de la quinzaine (PARRAIN-026, révise PARRAIN-017).
    case PARRAIN = 'parrain';

    public function label(): string
    {
        return match ($this) {
            self::VEHICULE => 'Véhicule',
            self::PROPRIETAIRE => 'Propriétaire',
            self::LIVREUR => 'Livreur',
            self::EMPLOYE => 'Salarié',
            self::INTERNE => 'Interne',
            self::PRESTATAIRE => 'Prestataire',
            self::CLIENT => 'Client',
            self::CONSULTANT => 'Consultant',
            self::PARRAIN => 'Parrain',
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
            self::CLIENT => 'Client',
            self::CONSULTANT => 'Consultant',
            self::PARRAIN => 'Parrain',
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
            self::CLIENT => 'Cette dépense sera déduite du cashback du client sélectionné.',
            self::CONSULTANT => 'Cette dépense sera déduite de la commission du consultant sélectionné.',
            self::PARRAIN => 'Cette dépense sera déduite de la commission quinzaine du parrain sélectionné.',
        };
    }

    public function needsBeneficiaire(): bool
    {
        return $this !== self::INTERNE;
    }

    /** Valeur enregistrée dans depenses.beneficiaire_type : la catégorie, sauf Consultant (prestataire). */
    public function beneficiaireType(): ?string
    {
        return match ($this) {
            self::INTERNE => null,
            self::CONSULTANT => self::PRESTATAIRE->value,
            default => $this->value,
        };
    }

    public function beneficiaireTable(): ?string
    {
        return match ($this) {
            self::INTERNE => null,
            self::EMPLOYE => 'employes',
            self::LIVREUR => 'livreurs',
            self::PROPRIETAIRE => 'proprietaires',
            self::VEHICULE => 'vehicules',
            self::PRESTATAIRE, self::CONSULTANT => 'prestataires',
            self::CLIENT => 'clients',
            self::PARRAIN => 'parrains',
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
            self::PRESTATAIRE, self::CONSULTANT => 'commission_prestataire',
            self::CLIENT => 'commission_cashback',
            self::PARRAIN => 'commission_parrain',
        };
    }

    public function periodeType(): string
    {
        return in_array($this, [self::LIVREUR, self::PARRAIN], true) ? 'quinzaine' : 'mensuelle';
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
