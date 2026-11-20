<?php

namespace App\Support\Paiements;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Une opération de paiement envoyée par l'écran (ADR 0069) : sa clé d'idempotence (en-tête
 * Idempotency-Key, générée par l'écran) et l'empreinte de ses paramètres. Rejouer la même clé avec
 * les mêmes paramètres restitue le résultat initial ; la rejouer avec d'autres paramètres est refusé.
 */
final class OperationPaiement
{
    public const ENTETE = 'Idempotency-Key';

    public const MESSAGE_CLE_ABSENTE = "Clé d'opération absente ou invalide : rechargez la page puis recommencez. Aucun paiement n'a été enregistré.";

    public const MESSAGE_CLE_REUTILISEE = "Cette opération a déjà servi pour un paiement différent : rechargez la page puis recommencez. Aucun paiement n'a été enregistré.";

    private function __construct(
        public readonly string $cle,
        public readonly string $empreinte,
    ) {}

    /**
     * @param  array<string, mixed>  $parametres  tout ce qui définit le paiement (fiches, montants, mode, compte, référence, date…)
     *
     * @throws ValidationException
     */
    public static function depuisRequete(Request $request, array $parametres): self
    {
        $cle = trim((string) $request->header(self::ENTETE, ''));
        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $cle) !== 1) {
            throw ValidationException::withMessages(['operation' => self::MESSAGE_CLE_ABSENTE]);
        }

        return new self($cle, self::empreinte($parametres));
    }

    /** Empreinte stable : l'ordre des clés et la forme des montants (« 50000 », 50000.0) n'y changent rien. */
    public static function empreinte(array $parametres): string
    {
        return hash('sha256', json_encode(self::normaliser($parametres), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Montant écrit toujours de la même façon dans une empreinte. */
    public static function montant(mixed $montant): string
    {
        return number_format(round((float) $montant, 2), 2, '.', '');
    }

    private static function normaliser(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return is_string($valeur) ? (trim($valeur) === '' ? null : trim($valeur)) : $valeur;
        }

        $normalise = array_map(self::normaliser(...), $valeur);
        if (! array_is_list($normalise)) {
            ksort($normalise);
        }

        return $normalise;
    }
}
