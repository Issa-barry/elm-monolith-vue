<?php

namespace App\Enums;

use App\Services\PeriodeComptableService;

enum TypePeriodePaiement: string
{
    case LIVREUR = 'livreur';
    case PROPRIETAIRE = 'proprietaire';
    case SALARIE = 'salarie';
    case SITE = 'site';
    case CONSULTANT = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::LIVREUR => 'Livreurs',
            self::PROPRIETAIRE => 'Propriétaires',
            self::SALARIE => 'Salariés',
            self::SITE => 'Sites',
            self::CONSULTANT => 'Consultants',
        };
    }

    /**
     * Délègue à PeriodeComptableService::periodicityFor() — source unique de
     * vérité. Avant correction, cette méthode renvoyait 'quinzaine' pour tous
     * les types (y compris PROPRIETAIRE), incohérent avec PeriodeComptableService
     * qui applique déjà du mensuel pour PROPRIETAIRE/SALARIE.
     */
    public function periodicity(): string
    {
        return PeriodeComptableService::periodicityFor($this->value);
    }

    /** Abréviation utilisée dans la référence auto-générée (ex: PAY-202607-P1-LIV). */
    public function abreviation(): string
    {
        return match ($this) {
            self::LIVREUR => 'LIV',
            self::PROPRIETAIRE => 'PRO',
            self::SALARIE => 'SAL',
            self::SITE => 'SIT',
            self::CONSULTANT => 'CON',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
