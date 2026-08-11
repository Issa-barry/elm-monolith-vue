<?php

namespace App\Services\ImportFlotte\Normalizers;

use App\Traits\PhoneHandlerTrait;

/**
 * Normalise un numéro de téléphone saisi librement (espaces, tirets,
 * parenthèses, zéro national, indicatif avec ou sans "+", préfixe "00")
 * vers le format international canonique "+{indicatif}{numéro local}".
 *
 * Dédiée à l'import flotte : ne modifie pas PhoneHandlerTrait, partagé avec
 * des controllers en production (Proprietaire/Prestataire/Client) dont le
 * comportement ne doit pas changer ici. Réutilise malgré tout la même table
 * de pays / longueurs locales via le trait, pour ne pas dupliquer la source
 * de vérité.
 */
class PhoneNormalizer
{
    use PhoneHandlerTrait;

    /**
     * @return array{telephone: ?string, erreur: ?string}
     */
    public function normalize(string $raw, string $codePays): array
    {
        $dial = self::supportedPays()[$codePays][1] ?? '+224';
        $dialDigits = $this->digitsOnly($dial);
        $expectedLength = self::phoneLocalLengths()[$codePays] ?? null;

        $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', trim($raw)) ?? trim($raw);

        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+'.substr($cleaned, 2);
        }

        $hasPlus = str_starts_with($cleaned, '+');
        $digits = $this->digitsOnly($cleaned);

        if ($digits === '') {
            return ['telephone' => null, 'erreur' => 'numéro vide.'];
        }

        $local = null;

        if ($hasPlus) {
            if (str_starts_with($digits, $dialDigits)) {
                $local = substr($digits, strlen($dialDigits));
            } else {
                $local = $digits;
            }
        } elseif (
            $dialDigits !== '' && str_starts_with($digits, $dialDigits)
            && $expectedLength !== null
            && strlen($digits) - strlen($dialDigits) === $expectedLength
        ) {
            // Indicatif national saisi sans "+" (ex: "224622000008") — retiré
            // uniquement si la longueur restante correspond exactement à la
            // longueur locale attendue, pour ne jamais tronquer à tort un
            // numéro local qui commencerait coïncidemment par les mêmes chiffres.
            $local = substr($digits, strlen($dialDigits));
        } else {
            $local = preg_replace('/^0/', '', $digits) ?? $digits;
        }

        if ($expectedLength !== null && strlen($local) !== $expectedLength) {
            return [
                'telephone' => null,
                'erreur' => "le numero doit contenir {$expectedLength} chiffres (indicatif {$dial} exclu).",
            ];
        }

        return ['telephone' => $dial.$local, 'erreur' => null];
    }
}
