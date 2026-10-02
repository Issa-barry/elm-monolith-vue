<?php

namespace App\Enums;

/**
 * Statut d'une anomalie du monitoring des commissions (une cible attendue mais non générée) —
 * toujours DÉRIVÉ des tentatives de génération et des enveloppes existantes (cf.
 * CommissionMonitoringService), jamais stocké : même principe que CommissionGenerationStatut.
 *
 * Pas de statut « en cours » : une relance s'exécute de façon synchrone sous verrou de la
 * source (CommissionEnveloppeGenerator::executerAvecTentative()) — il n'existe aucun état
 * intermédiaire observable.
 */
enum CommissionAnomalieStatut: string
{
    case NON_GENEREE = 'non_generee';
    case ECHEC_RECURRENT = 'echec_recurrent';
    case REGULARISEE = 'regularisee';
    case SANS_OBJET = 'sans_objet';

    public function label(): string
    {
        return match ($this) {
            self::NON_GENEREE => 'Non générée',
            self::ECHEC_RECURRENT => 'Échec récurrent',
            self::REGULARISEE => 'Régularisée',
            self::SANS_OBJET => 'Sans objet',
        };
    }

    public function estOuverte(): bool
    {
        return in_array($this, [self::NON_GENEREE, self::ECHEC_RECURRENT], true);
    }
}
