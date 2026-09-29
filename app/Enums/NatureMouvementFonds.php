<?php

namespace App\Enums;

/**
 * Nature d'un mouvement de fonds. Toutes les natures partagent le même workflow
 * (StatutMouvementFonds) ; elles diffèrent par leurs règles de création et de confirmation,
 * portées par MouvementFondsService, et par la contrepartie de leurs écritures
 * (MouvementFondsComptabilisationService).
 */
enum NatureMouvementFonds: string
{
    /** Entre deux agences : remise au siège, financement d'une agence. */
    case INTER_SITES = 'inter_sites';

    /** Versement d'une caisse dédiée à un agent vers une caisse de l'agence (même site). */
    case INTERNE_CAISSES = 'interne_caisses';

    /**
     * Règlement inter-agences (ADR 0012) : l'agence qui a encaissé des commandes d'une autre agence
     * lui reverse ces encaissements précis (mouvement_fonds_encaissements). Seule nature qui solde
     * une dette inter-agences — un mouvement « Entre agences » ordinaire n'en solde jamais.
     */
    case REGLEMENT_AGENCES = 'reglement_agences';

    public function label(): string
    {
        return match ($this) {
            self::INTER_SITES => 'Entre agences',
            self::INTERNE_CAISSES => 'Versement de caisse',
            self::REGLEMENT_AGENCES => 'Règlement inter-agences',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
