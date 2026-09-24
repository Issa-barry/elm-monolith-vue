<?php

namespace App\Enums;

/**
 * Niveau de preuve exigé pour confirmer une annulation exceptionnelle (cf.
 * AnnulationExceptionnelleService) — paramètre d'organisation
 * `Parametre::CLE_VENTES_ANNULATION_EXCEPTIONNELLE_CONFIRMATION`, défaut EMAIL_CODE. Les
 * contrôles métier, le motif, l'empreinte du récapitulatif et l'audit restent obligatoires dans
 * les deux modes : seul le code envoyé par e-mail disparaît en mode SIMPLE.
 */
enum ModeConfirmationAnnulationExceptionnelle: string
{
    case EMAIL_CODE = 'email_code';
    case SIMPLE = 'simple';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL_CODE => 'Code de validation par e-mail',
            self::SIMPLE => 'Confirmation simple',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EMAIL_CODE => 'Un code à usage unique sera envoyé au Super Admin avant chaque annulation exceptionnelle.',
            self::SIMPLE => 'Le Super Admin pourra confirmer directement l\'annulation après vérification du récapitulatif.',
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'description' => $case->description()],
            self::cases()
        );
    }
}
