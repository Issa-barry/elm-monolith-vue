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
     * une dette inter-agences — un mouvement « Transfert entre agences » ordinaire n'en solde jamais.
     */
    case REGLEMENT_AGENCES = 'reglement_agences';

    /**
     * Approvisionnement (ADR 0018) : sens inverse du versement — une caisse de l'agence remet des
     * espèces à la caisse dédiée d'un agent (même site). Seul l'agent titulaire de la caisse
     * destinataire confirme la réception, jamais l'envoyeur ni un administrateur à sa place.
     */
    case APPROVISIONNEMENT_CAISSE = 'approvisionnement_caisse';

    public function label(): string
    {
        return match ($this) {
            self::INTER_SITES => 'Transfert entre agences',
            self::INTERNE_CAISSES => 'Versement de caisse',
            self::REGLEMENT_AGENCES => 'Règlement inter-agences',
            self::APPROVISIONNEMENT_CAISSE => 'Approvisionnement de caisse',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
