<?php

namespace App\Enums;

/**
 * Cycle d'une version de transmission mensuelle RH → Comptabilité (docs/transmission-paie.md,
 * ADR 0040 révisé par ADR 0047) : brouillon (montants calculés, recalculables) → validée par les RH,
 * ce qui la fige ET la met à disposition de la Comptabilité (statut « transmise ») → remplacée par
 * une version suivante. Un brouillon peut être abandonné ; une validation pas encore reprise par la
 * Comptabilité peut être annulée (version conservée, statut « validation annulée »).
 *
 * « validee » (figée sans être disponible) ne subsiste que pour les versions validées avant
 * l'ADR 0047 ; aucune nouvelle version ne prend ce statut.
 */
enum StatutTransmissionPaie: string
{
    case BROUILLON = 'brouillon';
    case VALIDEE = 'validee';
    case TRANSMISE = 'transmise';
    case REMPLACEE = 'remplacee';
    case ABANDONNEE = 'abandonnee';
    case VALIDATION_ANNULEE = 'validation_annulee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDEE => 'Validée, non mise à disposition',
            self::TRANSMISE => 'Validée et figée par les RH',
            self::REMPLACEE => 'Remplacée',
            self::ABANDONNEE => 'Abandonnée',
            self::VALIDATION_ANNULEE => 'Validation annulée',
        };
    }

    /** Version validée : elle fige le mois côté RH (CalendrierPaieService) et ses lignes. */
    public function estFigee(): bool
    {
        return in_array($this, [self::VALIDEE, self::TRANSMISE, self::REMPLACEE], true);
    }

    /**
     * Contenu conservé tel quel (lignes, totaux, empreinte) : version figée, ou dont la validation a
     * été annulée — elle ne fige plus le mois, mais reste dans l'historique sans pouvoir changer.
     */
    public function estConservee(): bool
    {
        return $this->estFigee() || $this === self::VALIDATION_ANNULEE;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
