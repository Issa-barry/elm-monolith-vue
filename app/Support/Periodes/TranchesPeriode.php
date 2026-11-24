<?php

namespace App\Support\Periodes;

use Carbon\CarbonImmutable;

/**
 * Découpage d'une période en tranches pour le graphique d'évolution des tableaux de bord
 * (Stat-Ventes, Stat-Commissions) : une barre par jour jusqu'à 31 jours, une barre par mois au-delà.
 * Les tranches sont calculées ici, côté serveur : le graphique affiche ce qu'il reçoit et ne lit
 * jamais l'horloge du navigateur (ADR 0057).
 */
final class TranchesPeriode
{
    /** Au-delà de ce nombre de jours, l'évolution est présentée mois par mois. */
    public const JOURS_MAX_PAR_JOUR = 31;

    private const MOIS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    private function __construct(public readonly bool $parMois) {}

    public static function pour(Periode $periode): self
    {
        return new self(
            ! $periode->estBornee()
            || $periode->debut->diffInDays($periode->fin->startOfDay()) + 1 > self::JOURS_MAX_PAR_JOUR,
        );
    }

    public function granularite(): string
    {
        return $this->parMois ? 'mois' : 'jour';
    }

    /** Clé de tranche d'un jour (Y-m-d ou date-heure, quel que soit le stockage) : Y-m-d ou Y-m. */
    public function cle(string $jour): string
    {
        return substr($jour, 0, $this->parMois ? 7 : 10);
    }

    /**
     * Tranches dans l'ordre, sans trou : chaque jour (ou mois) de la période jusqu'à aujourd'hui ;
     * pour « Toutes les périodes », du premier au dernier mois qui a des données.
     *
     * @param  list<string>  $clesAvecDonnees  clés (cle()) des tranches qui ont des données
     * @return list<array{cle: string, label: string}>
     */
    public function tranches(Periode $periode, array $clesAvecDonnees, CarbonImmutable $maintenant): array
    {
        if ($periode->estBornee()) {
            $debut = $periode->debut;
            $fin = $periode->fin->lessThan($maintenant) ? $periode->fin : $maintenant;
        } elseif ($clesAvecDonnees !== []) {
            sort($clesAvecDonnees);
            $debut = CarbonImmutable::parse(substr($clesAvecDonnees[0], 0, 7).'-01');
            $fin = CarbonImmutable::parse(substr(end($clesAvecDonnees), 0, 7).'-01');
        } else {
            return [];
        }

        $dates = [];
        if ($this->parMois) {
            for ($mois = $debut->day(1)->startOfDay(); $mois->lessThanOrEqualTo($fin); $mois = $mois->addMonthNoOverflow()) {
                $dates[] = $mois;
            }
        } else {
            for ($jour = $debut->startOfDay(); $jour->lessThanOrEqualTo($fin); $jour = $jour->addDay()) {
                $dates[] = $jour;
            }
        }

        $plusieursAnnees = $dates !== [] && $dates[0]->year !== end($dates)->year;

        return array_map(fn (CarbonImmutable $date) => [
            'cle' => $date->format($this->parMois ? 'Y-m' : 'Y-m-d'),
            'label' => $this->parMois
                ? self::MOIS[$date->month - 1].($plusieursAnnees ? ' '.$date->format('y') : '')
                : $date->format('d/m'),
        ], $dates);
    }
}
