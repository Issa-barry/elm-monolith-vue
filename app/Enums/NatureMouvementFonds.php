<?php

namespace App\Enums;

/**
 * Nature d'un mouvement de fonds. Les deux natures partagent le même workflow et les mêmes
 * écritures (StatutMouvementFonds, MouvementFondsComptabilisationService) ; elles diffèrent par
 * leurs règles de création et de confirmation, portées par MouvementFondsService.
 */
enum NatureMouvementFonds: string
{
    /** Entre deux agences : remise au siège, financement d'une agence. */
    case INTER_SITES = 'inter_sites';

    /** Versement d'une caisse dédiée à un agent vers une caisse de l'agence (même site). */
    case INTERNE_CAISSES = 'interne_caisses';

    public function label(): string
    {
        return match ($this) {
            self::INTER_SITES => 'Entre agences',
            self::INTERNE_CAISSES => 'Versement de caisse',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
