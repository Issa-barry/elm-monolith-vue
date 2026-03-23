<?php

namespace App\Enums;

enum TypeVehicule: string
{
    case CAMION      = 'camion';
    case CAMIONNETTE = 'camionnette';
    case MOTO        = 'moto';
    case TRICYCLE    = 'tricycle';
    case VOITURE     = 'voiture';

    public function label(): string
    {
        return match($this) {
            self::CAMION      => 'Camion',
            self::CAMIONNETTE => 'Camionnette',
            self::MOTO        => 'Moto',
            self::TRICYCLE    => 'Tricycle',
            self::VOITURE     => 'Voiture',
        };
    }

    public function defaultCapacitePacks(): int
    {
        return match($this) {
            self::CAMION      => 200,
            self::CAMIONNETTE => 80,
            self::VOITURE     => 40,
            self::TRICYCLE    => 30,
            self::MOTO        => 10,
        };
    }

    public static function allowedValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(fn ($case) => [
            'value'            => $case->value,
            'label'            => $case->label(),
            'capacite_defaut'  => $case->defaultCapacitePacks(),
        ], self::cases());
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $lower = strtolower(trim($value));
        foreach (self::cases() as $case) {
            if ($case->value === $lower) {
                return $lower;
            }
        }
        return null;
    }
}
