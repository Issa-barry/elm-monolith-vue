<?php

namespace App\Enums;

enum StatutFinancementAgence: string
{
    case COUVERT = 'couvert';
    case A_FINANCER = 'a_financer';
    case FONDS_EN_TRANSIT = 'fonds_en_transit';
    case DONNEES_INCOMPLETES = 'donnees_incompletes';

    /** Fonds propres au-delà de ce qu'elle conserve, ou fonds d'autres agences : à remettre (ADR 0016). */
    case A_REMETTRE = 'a_remettre';

    /** Site central de trésorerie (ADR 0017) : ne se remet rien et ne se finance pas lui-même. */
    case TRESORERIE_PRINCIPALE = 'tresorerie_principale';

    public function label(): string
    {
        return match ($this) {
            self::COUVERT => 'Couvert',
            self::A_FINANCER => 'À financer',
            self::FONDS_EN_TRANSIT => 'Fonds en transit',
            self::DONNEES_INCOMPLETES => 'Données incomplètes',
            self::A_REMETTRE => 'À remettre',
            self::TRESORERIE_PRINCIPALE => 'Trésorerie principale',
        };
    }
}
