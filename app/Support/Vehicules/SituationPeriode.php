<?php

namespace App\Support\Vehicules;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Période unique de l'onglet Situation d'un véhicule : toutes les sections (ventes, et celles à
 * venir) filtrent sur les mêmes bornes, résolues ici une seule fois.
 *
 * Les périodes rapides sont résolues côté serveur (jamais dans le navigateur) : « aujourd'hui »
 * et le début de semaine restent ceux de l'application. Semaine = du lundi au dimanche.
 * Période personnalisée = `date_from` + `date_to` (Y-m-d, bornes incluses), toutes deux
 * obligatoires et ordonnées ; sinon on retombe sur « Toute la période ».
 */
final class SituationPeriode
{
    public const TOUT = 'tout';

    public const PERSONNALISEE = 'personnalisee';

    private const LIBELLES = [
        self::TOUT => 'Toute la période',
        'aujourd_hui' => "Aujourd'hui",
        'hier' => 'Hier',
        'cette_semaine' => 'Cette semaine',
        'semaine_precedente' => 'Semaine précédente',
        'ce_mois' => 'Ce mois',
        'mois_precedent' => 'Mois précédent',
        'cette_annee' => 'Cette année',
        'annee_precedente' => 'Année précédente',
        self::PERSONNALISEE => 'Période personnalisée',
    ];

    private function __construct(
        public readonly string $cle,
        public readonly ?CarbonImmutable $debut,
        public readonly ?CarbonImmutable $fin,
    ) {}

    public static function depuisRequete(Request $request, ?CarbonImmutable $maintenant = null): self
    {
        $maintenant ??= CarbonImmutable::now();
        $du = $request->query('date_from');
        $au = $request->query('date_to');

        if (self::rempli($du) || self::rempli($au)) {
            return self::personnalisee($du, $au);
        }

        return self::rapide((string) $request->query('situation_periode', self::TOUT), $maintenant);
    }

    /**
     * @return array{cle: string, date_debut: ?string, date_fin: ?string, options: list<array{value: string, label: string}>}
     */
    public function pourFront(): array
    {
        return [
            'cle' => $this->cle,
            'date_debut' => $this->debut?->toDateString(),
            'date_fin' => $this->fin?->toDateString(),
            'options' => self::options(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $cle, string $label) => ['value' => $cle, 'label' => $label],
            array_keys(self::LIBELLES),
            self::LIBELLES,
        );
    }

    private static function tout(): self
    {
        return new self(self::TOUT, null, null);
    }

    private static function rapide(string $cle, CarbonImmutable $maintenant): self
    {
        $lundi = CarbonInterface::MONDAY;
        $dimanche = CarbonInterface::SUNDAY;

        $bornes = match ($cle) {
            'aujourd_hui' => [$maintenant->startOfDay(), $maintenant->endOfDay()],
            'hier' => [$maintenant->subDay()->startOfDay(), $maintenant->subDay()->endOfDay()],
            'cette_semaine' => [$maintenant->startOfWeek($lundi), $maintenant->endOfWeek($dimanche)],
            'semaine_precedente' => [$maintenant->subWeek()->startOfWeek($lundi), $maintenant->subWeek()->endOfWeek($dimanche)],
            'ce_mois' => [$maintenant->startOfMonth(), $maintenant->endOfMonth()],
            'mois_precedent' => [$maintenant->subMonthNoOverflow()->startOfMonth(), $maintenant->subMonthNoOverflow()->endOfMonth()],
            'cette_annee' => [$maintenant->startOfYear(), $maintenant->endOfYear()],
            'annee_precedente' => [$maintenant->subYearNoOverflow()->startOfYear(), $maintenant->subYearNoOverflow()->endOfYear()],
            default => null,
        };

        return $bornes === null ? self::tout() : new self($cle, $bornes[0], $bornes[1]);
    }

    private static function personnalisee(mixed $du, mixed $au): self
    {
        $debut = self::date($du);
        $fin = self::date($au);

        if ($debut === null || $fin === null || $debut->greaterThan($fin)) {
            return self::tout();
        }

        return new self(self::PERSONNALISEE, $debut->startOfDay(), $fin->endOfDay());
    }

    private static function rempli(mixed $valeur): bool
    {
        return is_string($valeur) && $valeur !== '';
    }

    /** Refuse tout ce qui n'est pas une vraie date Y-m-d (ex. 2026-02-31, qui déborderait sur mars). */
    private static function date(mixed $valeur): ?CarbonImmutable
    {
        if (! is_string($valeur) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date !== false && $date->format('Y-m-d') === $valeur ? $date : null;
    }
}
