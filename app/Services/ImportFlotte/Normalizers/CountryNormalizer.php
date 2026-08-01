<?php

namespace App\Services\ImportFlotte\Normalizers;

use App\Traits\PhoneHandlerTrait;

/**
 * Résout une valeur de pays saisie librement (code ISO alpha-2/alpha-3, nom
 * français, avec ou sans accents/casse) vers le code alpha-2 canonique déjà
 * utilisé dans le projet (PhoneHandlerTrait::supportedPays() reste l'unique
 * source de vérité — cette classe ne fait qu'y ajouter des clés de recherche
 * tolérantes).
 */
class CountryNormalizer
{
    use PhoneHandlerTrait;

    /**
     * Codes ISO 3166-1 alpha-3 des pays déjà supportés par le projet.
     * Ce mapping ne fait qu'ajouter une clé de recherche supplémentaire vers
     * les codes alpha-2 existants — il n'introduit aucun nouveau pays.
     */
    private const ALPHA3 = [
        'GIN' => 'GN', 'GNB' => 'GW', 'SEN' => 'SN', 'MLI' => 'ML', 'CIV' => 'CI',
        'LBR' => 'LR', 'SLE' => 'SL', 'FRA' => 'FR', 'CHN' => 'CN', 'ARE' => 'AE',
        'IND' => 'IN', 'CAN' => 'CA',
        'AUT' => 'AT', 'BEL' => 'BE', 'BGR' => 'BG', 'HRV' => 'HR', 'CZE' => 'CZ',
        'DNK' => 'DK', 'EST' => 'EE', 'FIN' => 'FI', 'DEU' => 'DE', 'GRC' => 'GR',
        'HUN' => 'HU', 'ISL' => 'IS', 'ITA' => 'IT', 'LVA' => 'LV', 'LIE' => 'LI',
        'LTU' => 'LT', 'LUX' => 'LU', 'MLT' => 'MT', 'NLD' => 'NL', 'NOR' => 'NO',
        'POL' => 'PL', 'PRT' => 'PT', 'ROU' => 'RO', 'SVK' => 'SK', 'SVN' => 'SI',
        'ESP' => 'ES', 'SWE' => 'SE', 'CHE' => 'CH',
    ];

    public static function resolve(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $upper = mb_strtoupper($raw, 'UTF-8');
        if (isset(self::supportedPays()[$upper])) {
            return $upper;
        }

        if (isset(self::ALPHA3[$upper])) {
            return self::ALPHA3[$upper];
        }

        $normalized = ImportTextNormalizer::normalize($raw);
        foreach (self::supportedPays() as $code => [$nom, $indicatif]) {
            if (ImportTextNormalizer::normalize($nom) === $normalized) {
                return $code;
            }
        }

        return null;
    }
}
