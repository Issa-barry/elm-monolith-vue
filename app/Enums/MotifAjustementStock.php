<?php

namespace App\Enums;

enum MotifAjustementStock: string
{
    // Augmentation uniquement
    case APRES_PRODUCTION = 'apres_production';
    case RETOUR = 'retour';

    // Diminution uniquement
    case PERTE = 'perte';
    case CASSE = 'casse';
    case DON = 'don';
    case SORTIE_EXCEPTIONNELLE = 'sortie_exceptionnelle';

    // Les deux directions
    case CORRECTION_STOCK = 'correction_stock';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::APRES_PRODUCTION => 'Après production',
            self::RETOUR => 'Retour',
            self::PERTE => 'Perte',
            self::CASSE => 'Casse',
            self::DON => 'Don',
            self::SORTIE_EXCEPTIONNELLE => 'Sortie exceptionnelle',
            self::CORRECTION_STOCK => 'Correction de stock',
            self::AUTRE => 'Autre',
        };
    }

    /** Direction du motif : 'entree', 'sortie', ou 'both'. */
    public function direction(): string
    {
        return match ($this) {
            self::APRES_PRODUCTION,
            self::RETOUR => 'entree',

            self::PERTE,
            self::CASSE,
            self::DON,
            self::SORTIE_EXCEPTIONNELLE => 'sortie',

            self::CORRECTION_STOCK,
            self::AUTRE => 'both',
        };
    }

    public function toNotesString(string $detail = ''): string
    {
        if ($this === self::AUTRE) {
            return 'Autre'.($detail !== '' ? ' : '.$detail : '');
        }

        return $this->label();
    }

    /** Toutes les valeurs valides. */
    public static function validValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Valeurs valides pour une direction donnée ('entree' ou 'sortie'). */
    public static function validValuesForDirection(string $direction): array
    {
        return array_values(array_column(
            array_filter(
                self::cases(),
                fn (self $case) => in_array($case->direction(), [$direction, 'both'], true)
            ),
            'value'
        ));
    }

    /** Listes prêtes pour le front, indexées par direction. */
    public static function forFront(): array
    {
        $build = fn (string $dir) => array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            array_values(array_filter(
                self::cases(),
                fn (self $c) => in_array($c->direction(), [$dir, 'both'], true)
            ))
        );

        return [
            'augmentation' => $build('entree'),
            'diminution' => $build('sortie'),
        ];
    }
}
