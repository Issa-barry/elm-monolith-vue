<?php

namespace App\Support\Periodes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Seul calculateur des périodes calendaires de l'application (ADR 0057, docs/periodes.md) :
 * back-office, espace client et API mobile. Le front transmet une clé (`periode`) ou deux dates
 * (`date_debut` + `date_fin`, Y-m-d, incluses ; sans `date_fin` = jusqu'à aujourd'hui) ; les bornes sont toujours résolues ici, avec
 * l'horloge et le fuseau de l'application (UTC = heure de Conakry). Semaine = lundi → dimanche.
 *
 * Les périodes métier ne passent pas par ici : quinzaines P1/P2 et mois comptable
 * (PeriodeComptableFiltre, CommissionPeriodeFilter, PeriodeComptableService), périodes de paie.
 */
final class Periode
{
    public const TOUT = 'tout';

    public const PERSONNALISEE = 'personnalisee';

    public const RAPIDES = [
        'aujourd_hui',
        'hier',
        'cette_semaine',
        'semaine_precedente',
        'ce_mois',
        'mois_precedent',
        'cette_annee',
        'annee_precedente',
    ];

    /** Périodes de l'année en cours, proposées seulement par le tableau de bord. */
    public const TRIMESTRES_SEMESTRES = ['t1', 't2', 't3', 't4', 's1', 's2'];

    /** Choix standard d'un filtre de période : périodes rapides, toutes les périodes, dates libres. */
    public const STANDARD = [...self::RAPIDES, self::TOUT, self::PERSONNALISEE];

    private const LIBELLES = [
        'aujourd_hui' => "Aujourd'hui",
        'hier' => 'Hier',
        'cette_semaine' => 'Cette semaine',
        'semaine_precedente' => 'Semaine dernière',
        'ce_mois' => 'Ce mois',
        'mois_precedent' => 'Mois dernier',
        't1' => 'T1',
        't2' => 'T2',
        't3' => 'T3',
        't4' => 'T4',
        's1' => 'S1',
        's2' => 'S2',
        'cette_annee' => 'Cette année',
        'annee_precedente' => 'Année dernière',
        self::TOUT => 'Toutes les périodes',
        self::PERSONNALISEE => 'Période personnalisée',
    ];

    /** Anciennes clés encore présentes dans des liens et des vues enregistrées. */
    private const ALIAS = [
        'today' => 'aujourd_hui',
        'week' => 'cette_semaine',
        'month' => 'ce_mois',
        'all' => self::TOUT,
        'semaine_derniere' => 'semaine_precedente',
        'mois_dernier' => 'mois_precedent',
    ];

    /** Anciens noms des dates libres (fiche véhicule, fiche agent, rapport d'activité). */
    private const ALIAS_DATES = ['date_debut' => 'date_from', 'date_fin' => 'date_to'];

    /**
     * @param  ?CarbonImmutable  $debut  début du premier jour (inclus)
     * @param  ?CarbonImmutable  $fin  fin du dernier jour (inclus) ; voir finExclue() pour les requêtes
     * @param  bool  $finOuverte  dates libres sans `date_fin` : la fin suit le jour courant
     */
    private function __construct(
        public readonly string $cle,
        public readonly ?CarbonImmutable $debut,
        public readonly ?CarbonImmutable $fin,
        public readonly bool $finOuverte = false,
    ) {}

    /**
     * Valide les paramètres de période de la requête (erreur explicite, jamais de repli silencieux)
     * puis les résout. Point d'entrée des écrans et des exports.
     *
     * @param  list<string>|null  $cles  choix proposés par l'écran (null = STANDARD)
     */
    public static function depuisRequeteValidee(
        Request $request,
        string $defaut = self::TOUT,
        ?array $cles = null,
        string $parametre = 'periode',
    ): self {
        self::valider($request, $cles, $parametre);

        return self::depuisRequete($request, null, $parametre, $defaut, $cles);
    }

    /**
     * Résolution sans validation : une entrée invalide retombe sur `$defaut`. À n'utiliser que
     * derrière une validation (FormRequest, depuisRequeteValidee) ou dans les tests.
     *
     * @param  list<string>|null  $cles  choix proposés par l'écran (null = toutes les clés connues)
     */
    public static function depuisRequete(
        Request $request,
        ?CarbonImmutable $maintenant = null,
        string $parametre = 'periode',
        string $defaut = self::TOUT,
        ?array $cles = null,
    ): self {
        $maintenant ??= CarbonImmutable::now();
        $du = self::lireDate($request, 'date_debut');
        $au = self::lireDate($request, 'date_fin');
        $datesPermises = $cles === null || in_array(self::PERSONNALISEE, $cles, true);

        if ($datesPermises && (self::rempli($du) || self::rempli($au))) {
            $periode = self::personnalisee($du, $au, $maintenant);
        } else {
            $cle = self::normaliserCle((string) $request->query($parametre, $defaut));
            $periode = $cles === null || in_array($cle, $cles, true) ? self::rapide($cle, $maintenant) : null;
            $periode ??= $cle === self::TOUT && ($cles === null || in_array(self::TOUT, $cles, true)) ? self::tout() : null;
        }

        return $periode ?? self::rapide($defaut, $maintenant) ?? self::tout();
    }

    /**
     * Règles communes : `periode` connue, dates Y-m-d ordonnées, `periode` exclusive des dates. Une
     * date de fin exige une date de début ; une date de début seule va jusqu'à aujourd'hui (donc pas
     * dans le futur). Les anciennes clés (ALIAS) restent acceptées.
     *
     * @param  list<string>|null  $cles
     * @return array<string, array<int, mixed>>
     */
    public static function regles(?array $cles = null, string $parametre = 'periode'): array
    {
        $cles ??= self::STANDARD;
        $acceptees = self::clesAcceptees($cles);

        // Écran sans dates libres (ex. tableau de bord) : des dates sont refusées, jamais ignorées.
        if (! in_array(self::PERSONNALISEE, $cles, true)) {
            return [
                $parametre => ['nullable', 'string', Rule::in($acceptees)],
                'date_debut' => ['prohibited'],
                'date_fin' => ['prohibited'],
            ];
        }

        return [
            $parametre => ['nullable', 'string', Rule::in($acceptees), 'prohibits:date_debut,date_fin'],
            'date_debut' => ['nullable', 'date_format:Y-m-d', 'required_with:date_fin', self::debutSansFinPasDansLeFutur()],
            'date_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
        ];
    }

    /**
     * Valeurs acceptées pour la clé `periode` (anciennes clés comprises), dates libres exclues :
     * aussi utilisées par les vues enregistrées (SavedFilterScopes).
     *
     * @param  list<string>|null  $cles
     * @return list<string>
     */
    public static function clesAcceptees(?array $cles = null): array
    {
        $acceptees = array_values(array_diff($cles ?? self::STANDARD, [self::PERSONNALISEE]));
        $alias = array_keys(array_filter(self::ALIAS, fn (string $cible) => in_array($cible, $acceptees, true)));

        return [...$acceptees, ...$alias];
    }

    /** @return array<string, string> */
    public static function messages(string $parametre = 'periode'): array
    {
        return [
            "{$parametre}.in" => 'Période inconnue.',
            "{$parametre}.prohibits" => 'Choisissez une période ou des dates, pas les deux.',
            'date_debut.required_with' => 'Renseignez aussi la date de début.',
            'date_debut.date_format' => 'La date de début est invalide.',
            'date_fin.date_format' => 'La date de fin est invalide.',
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'date_debut.prohibited' => 'Cet écran ne propose pas de dates libres : choisissez une période.',
            'date_fin.prohibited' => 'Cet écran ne propose pas de dates libres : choisissez une période.',
        ];
    }

    /** @param  list<string>|null  $cles */
    public static function valider(Request $request, ?array $cles = null, string $parametre = 'periode'): void
    {
        self::normaliserRequete($request, $parametre);

        Validator::make($request->query(), self::regles($cles, $parametre), self::messages($parametre))->validate();
    }

    public static function rapide(string $cle, CarbonImmutable $maintenant): ?self
    {
        $cle = self::normaliserCle($cle);
        $lundi = CarbonInterface::MONDAY;
        $dimanche = CarbonInterface::SUNDAY;
        $annee = $maintenant->startOfYear();

        $bornes = match ($cle) {
            'aujourd_hui' => [$maintenant->startOfDay(), $maintenant->endOfDay()],
            'hier' => [$maintenant->subDay()->startOfDay(), $maintenant->subDay()->endOfDay()],
            'cette_semaine' => [$maintenant->startOfWeek($lundi), $maintenant->endOfWeek($dimanche)],
            'semaine_precedente' => [$maintenant->subWeek()->startOfWeek($lundi), $maintenant->subWeek()->endOfWeek($dimanche)],
            'ce_mois' => [$maintenant->startOfMonth(), $maintenant->endOfMonth()],
            'mois_precedent' => [$maintenant->subMonthNoOverflow()->startOfMonth(), $maintenant->subMonthNoOverflow()->endOfMonth()],
            'cette_annee' => [$annee, $maintenant->endOfYear()],
            'annee_precedente' => [$maintenant->subYearNoOverflow()->startOfYear(), $maintenant->subYearNoOverflow()->endOfYear()],
            't1' => [$annee, $annee->addMonthsNoOverflow(2)->endOfMonth()],
            't2' => [$annee->addMonthsNoOverflow(3), $annee->addMonthsNoOverflow(5)->endOfMonth()],
            't3' => [$annee->addMonthsNoOverflow(6), $annee->addMonthsNoOverflow(8)->endOfMonth()],
            't4' => [$annee->addMonthsNoOverflow(9), $annee->addMonthsNoOverflow(11)->endOfMonth()],
            's1' => [$annee, $annee->addMonthsNoOverflow(5)->endOfMonth()],
            's2' => [$annee->addMonthsNoOverflow(6), $annee->addMonthsNoOverflow(11)->endOfMonth()],
            default => null,
        };

        return $bornes === null ? null : new self($cle, $bornes[0], $bornes[1]);
    }

    public function estBornee(): bool
    {
        return $this->debut !== null && $this->fin !== null;
    }

    /** Lendemain du dernier jour à 00:00 : borne exclue des requêtes (insensible aux microsecondes). */
    public function finExclue(): ?CarbonImmutable
    {
        return $this->fin?->startOfDay()->addDay();
    }

    /**
     * Restreint `$colonne` à la période en bornes semi-ouvertes (début inclus, lendemain exclu), sans
     * fonction SQL sur la colonne pour garder son index. Sans effet pour « Toutes les périodes ».
     *
     * @param  bool  $colonneDate  colonne de type DATE (ex. date_depense) : comparée en Y-m-d
     */
    public function appliquer(Builder $query, string $colonne, bool $colonneDate = false): Builder
    {
        if (! $this->estBornee()) {
            return $query;
        }

        return $colonneDate
            ? $query->where($colonne, '>=', $this->debut->toDateString())->where($colonne, '<', $this->finExclue()->toDateString())
            : $query->where($colonne, '>=', $this->debut->toDateTimeString())->where($colonne, '<', $this->finExclue()->toDateTimeString());
    }

    /** Même règle que appliquer(), pour des lignes déjà chargées en PHP. */
    public function contient(?CarbonInterface $date): bool
    {
        if (! $this->estBornee()) {
            return true;
        }

        return $date !== null && $date->greaterThanOrEqualTo($this->debut) && $date->lessThan($this->finExclue());
    }

    /**
     * @param  list<string>|null  $cles  sous-ensemble des périodes proposées (null = STANDARD)
     * @return array{cle: string, date_debut: ?string, date_fin: ?string, options: list<array{value: string, label: string}>}
     */
    public function pourFront(?array $cles = null): array
    {
        return [
            'cle' => $this->cle,
            'date_debut' => $this->debut?->toDateString(),
            'date_fin' => $this->fin?->toDateString(),
            'options' => self::options($cles),
        ];
    }

    /**
     * Valeurs à renvoyer au champ `period` de DataFilters : la clé, et les dates seulement pour une
     * période personnalisée (une période rapide se rejoue toujours côté serveur).
     *
     * @return array<string, ?string>
     */
    public function valeursFiltre(string $parametre = 'periode'): array
    {
        $personnalisee = $this->cle === self::PERSONNALISEE;

        // Fin ouverte renvoyée vide : une vue enregistrée continue de suivre le jour courant.
        return [
            $parametre => $this->cle,
            'date_debut' => $personnalisee ? $this->debut?->toDateString() : null,
            'date_fin' => $personnalisee && ! $this->finOuverte ? $this->fin?->toDateString() : null,
        ];
    }

    /** Libellé lisible (exports) : « Aujourd'hui (26/09/2026) », « Du 01/09/2026 au 26/09/2026 ». */
    public function libelle(): string
    {
        if (! $this->estBornee()) {
            return self::LIBELLES[self::TOUT];
        }

        $du = $this->debut->format('d/m/Y');
        $au = $this->fin->format('d/m/Y');
        $bornes = $du === $au ? $du : "du {$du} au {$au}";

        return $this->cle === self::PERSONNALISEE
            ? ucfirst($bornes)
            : self::LIBELLES[$this->cle]." ({$bornes})";
    }

    /**
     * @param  list<string>|null  $cles  sous-ensemble des périodes proposées (null = STANDARD)
     * @return list<array{value: string, label: string}>
     */
    public static function options(?array $cles = null): array
    {
        $cles ??= self::STANDARD;
        $libelles = array_intersect_key(self::LIBELLES, array_flip($cles));

        return array_map(
            fn (string $cle, string $label) => ['value' => $cle, 'label' => $label],
            array_keys($libelles),
            $libelles,
        );
    }

    /**
     * Options avec leurs bornes résolues, pour un écran qui filtre côté navigateur des données déjà
     * chargées (graphique du tableau de bord) sans recalculer les dates avec l'horloge du navigateur.
     *
     * @param  list<string>  $cles
     * @return list<array{value: string, label: string, date_debut: ?string, date_fin: ?string}>
     */
    public static function optionsAvecBornes(array $cles, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();

        return array_map(function (array $option) use ($maintenant) {
            $periode = self::rapide($option['value'], $maintenant);

            return $option + [
                'date_debut' => $periode?->debut->toDateString(),
                'date_fin' => $periode?->fin->toDateString(),
            ];
        }, self::options($cles));
    }

    public static function normaliserCle(string $cle): string
    {
        return self::ALIAS[$cle] ?? $cle;
    }

    private static function tout(): self
    {
        return new self(self::TOUT, null, null);
    }

    private static function personnalisee(mixed $du, mixed $au, CarbonImmutable $maintenant): ?self
    {
        $finOuverte = ! self::rempli($au);
        $debut = self::date($du);
        $fin = $finOuverte ? $maintenant->startOfDay() : self::date($au);

        if ($debut === null || $fin === null || $debut->greaterThan($fin)) {
            return null;
        }

        return new self(self::PERSONNALISEE, $debut->startOfDay(), $fin->endOfDay(), $finOuverte);
    }

    /** Sans date de fin, la période s'arrête aujourd'hui : un début dans le futur serait vide. */
    private static function debutSansFinPasDansLeFutur(): ValidationRule
    {
        return new class implements DataAwareRule, ValidationRule
        {
            /** @var array<string, mixed> */
            private array $donnees = [];

            public function setData(array $data): static
            {
                $this->donnees = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $fin = $this->donnees['date_fin'] ?? null;

                if (is_string($fin) && $fin !== '') {
                    return;
                }

                if (is_string($value) && $value > CarbonImmutable::now()->toDateString()) {
                    $fail("Sans date de fin, la date de début ne peut pas dépasser aujourd'hui.");
                }
            }
        };
    }

    /**
     * Anciens liens : `date_from`/`date_to` deviennent `date_debut`/`date_fin` ; une clé « sans
     * effet » (`all`, `tout`, `personnalisee`) envoyée avec des dates est retirée plutôt que de
     * rendre la requête contradictoire (vues enregistrées de Ventes, qui ajoutaient `periode=all`).
     */
    private static function normaliserRequete(Request $request, string $parametre): void
    {
        foreach (self::ALIAS_DATES as $standard => $ancien) {
            if (! self::rempli($request->query($standard)) && self::rempli($request->query($ancien))) {
                $request->query->set($standard, $request->query($ancien));
            }
        }

        $avecDates = self::rempli($request->query('date_debut')) || self::rempli($request->query('date_fin'));

        if ($avecDates && in_array($request->query($parametre), ['all', self::TOUT, self::PERSONNALISEE], true)) {
            $request->query->remove($parametre);
        }
    }

    private static function lireDate(Request $request, string $standard): mixed
    {
        $valeur = $request->query($standard);

        return self::rempli($valeur) ? $valeur : $request->query(self::ALIAS_DATES[$standard]);
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
