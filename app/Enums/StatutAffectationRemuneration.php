<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Statut enregistré d'une affectation de prime ou d'avantage à un salarié
 * (docs/primes-avantages.md, ADR 0039) : à valider → validée ; annulée seulement tant qu'elle n'a
 * jamais été transmise. Une affectation validée ne repasse jamais « à valider » : un changement de
 * montant crée une nouvelle affectation qui lui succède, une fin d'effet se demande puis se valide.
 *
 * L'état affiché (à venir, en cours, terminée) se déduit des dates : etat().
 */
enum StatutAffectationRemuneration: string
{
    case A_VALIDER = 'a_valider';
    case VALIDEE = 'validee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::A_VALIDER => 'À valider',
            self::VALIDEE => 'Validée',
            self::ANNULEE => 'Annulée',
        };
    }

    /**
     * État affiché à une date : « à valider », « annulée », ou, pour une affectation validée,
     * « à venir », « en cours » ou « terminée » selon ses dates d'effet.
     *
     * @return array{value: string, label: string}
     */
    public function etat(CarbonInterface $debut, ?CarbonInterface $fin, CarbonInterface $jour): array
    {
        if ($this !== self::VALIDEE) {
            return ['value' => $this->value, 'label' => $this->label()];
        }

        return match (true) {
            $debut->gt($jour) => ['value' => 'a_venir', 'label' => 'À venir'],
            $fin !== null && $fin->lt($jour) => ['value' => 'terminee', 'label' => 'Terminée'],
            default => ['value' => 'en_cours', 'label' => 'En cours'],
        };
    }

    /** Statuts qui occupent la période (contrôle des chevauchements). */
    public static function occupantLaPeriode(): array
    {
        return [self::A_VALIDER->value, self::VALIDEE->value];
    }

    /** @return list<array{value: string, label: string}> */
    public static function optionsEtat(): array
    {
        return [
            ['value' => 'a_valider', 'label' => 'À valider'],
            ['value' => 'a_venir', 'label' => 'À venir'],
            ['value' => 'en_cours', 'label' => 'En cours'],
            ['value' => 'terminee', 'label' => 'Terminée'],
            ['value' => 'annulee', 'label' => 'Annulée'],
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
