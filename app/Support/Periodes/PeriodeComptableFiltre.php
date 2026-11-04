<?php

namespace App\Support\Periodes;

use App\Services\PeriodeComptableService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Filtre « période comptable » de l'espace client (Accueil, Commissions, Ventes, Dépenses) : année,
 * puis mois, puis quinzaine (P1 du 1er au 15, P2 du 16 à la fin du mois, TOUT le mois entier). Une
 * année sans mois couvre l'année entière. Calendrier fixe : les bornes viennent de
 * PeriodeComptableService, comme les périodes de paiement, jamais du navigateur.
 *
 * Paramètre `quinzaine` et non `periode` : `periode` désigne déjà les périodes rapides. La colonne de
 * date reste propre à chaque module (voir ADR 0053).
 */
final class PeriodeComptableFiltre
{
    public const TOUT = 'TOUT';

    public const QUINZAINES = [PeriodeComptableService::PART_P1, PeriodeComptableService::PART_P2, self::TOUT];

    private const PREMIERE_ANNEE = 2000;

    private function __construct(
        public readonly int $annee,
        public readonly ?int $mois,
        public readonly ?string $quinzaine,
        public readonly CarbonImmutable $debut,
        public readonly CarbonImmutable $fin,
    ) {}

    /**
     * @param  list<string>  $exclus  autres filtres de date de l'endpoint : les combiner donne un 422
     *                                plutôt qu'un choix silencieux entre deux périodes contradictoires
     * @return array<string, array<int, mixed>>
     */
    public static function regles(array $exclus): array
    {
        return [
            'annee' => [
                'nullable',
                'integer',
                'min:'.self::PREMIERE_ANNEE,
                // Pas de règle `max:` : l'année en cours finirait dans docs/openapi/client.json, et
                // le contrôle CI de cet export échouerait à chaque changement d'année.
                function (string $attribute, mixed $value, Closure $fail) {
                    if ((int) $value > CarbonImmutable::now()->year) {
                        $fail('L\'année ne peut pas être dans le futur.');
                    }
                },
                'required_with:mois,quinzaine',
                'prohibits:'.implode(',', $exclus),
            ],
            'mois' => ['nullable', 'integer', 'min:1', 'max:12', 'required_with:quinzaine'],
            'quinzaine' => ['nullable', Rule::in(self::QUINZAINES)],
        ];
    }

    /** À appeler après validation. `mois` omis = l'année entière, `quinzaine` omise = le mois entier. */
    public static function depuisRequete(Request $request): ?self
    {
        $annee = $request->input('annee');

        if ($annee === null) {
            return null;
        }

        $mois = $request->input('mois');

        if ($mois === null) {
            $debut = CarbonImmutable::create((int) $annee)->startOfYear();

            return new self((int) $annee, null, null, $debut, $debut->endOfYear());
        }

        $quinzaine = (string) ($request->input('quinzaine') ?? self::TOUT);
        $partie = $quinzaine === self::TOUT ? PeriodeComptableService::PART_M : $quinzaine;

        [$debut, $fin] = PeriodeComptableService::dateRangeForCode(sprintf('%04d-%02d-%s', $annee, $mois, $partie));

        return new self((int) $annee, (int) $mois, $quinzaine, $debut->toImmutable(), $fin->toImmutable());
    }
}
