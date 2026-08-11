<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Résout pays/indicatif/devise/fuseau horaire à partir d'un numéro de téléphone international,
 * via `giggsey/libphonenumber-for-php` (port PHP de la bibliothèque Google libphonenumber) pour
 * la validation et la détection du pays — pas de if/else codé en dur par indicatif.
 *
 * Devise et fuseau horaire n'ont PAS d'équivalent dans libphonenumber (qui ne connaît que
 * numérotation/région) : complétés par une petite table statique, volontairement limitée aux
 * pays déjà couverts ailleurs dans le projet (cf. PhoneHandlerTrait::supportedPays()) — à
 * étendre au besoin. Un pays valide mais absent de cette table reste accepté (nom = code ISO,
 * devise/fuseau à null) plutôt que rejeté : mieux vaut une info affichage incomplète qu'un blocage.
 *
 * Purement informatif pour l'instant : devise et fuseau horaire ne sont stockés nulle part
 * (aucune colonne Organization dédiée) — l'app reste mono-devise (GNF, en dur dans l'UI) tant
 * qu'un vrai besoin multi-devises n'existe pas. code_pays/pays/code_phone_pays, eux, sont bien
 * persistés (colonnes users existantes).
 */
class PhoneCountryInfo
{
    /** @var array<string, array{nom: string, devise: string, fuseau: string}> */
    private const ENRICHISSEMENT = [
        'GN' => ['nom' => 'Guinée', 'devise' => 'GNF', 'fuseau' => 'Africa/Conakry'],
        'GW' => ['nom' => 'Guinée-Bissau', 'devise' => 'XOF', 'fuseau' => 'Africa/Bissau'],
        'SN' => ['nom' => 'Sénégal', 'devise' => 'XOF', 'fuseau' => 'Africa/Dakar'],
        'ML' => ['nom' => 'Mali', 'devise' => 'XOF', 'fuseau' => 'Africa/Bamako'],
        'CI' => ['nom' => "Côte d'Ivoire", 'devise' => 'XOF', 'fuseau' => 'Africa/Abidjan'],
        'LR' => ['nom' => 'Liberia', 'devise' => 'LRD', 'fuseau' => 'Africa/Monrovia'],
        'SL' => ['nom' => 'Sierra Leone', 'devise' => 'SLE', 'fuseau' => 'Africa/Freetown'],
        'FR' => ['nom' => 'France', 'devise' => 'EUR', 'fuseau' => 'Europe/Paris'],
        'CN' => ['nom' => 'Chine', 'devise' => 'CNY', 'fuseau' => 'Asia/Shanghai'],
        'AE' => ['nom' => 'Émirats arabes unis', 'devise' => 'AED', 'fuseau' => 'Asia/Dubai'],
        'IN' => ['nom' => 'Inde', 'devise' => 'INR', 'fuseau' => 'Asia/Kolkata'],
        'CA' => ['nom' => 'Canada', 'devise' => 'CAD', 'fuseau' => 'America/Toronto'],
        'AT' => ['nom' => 'Autriche', 'devise' => 'EUR', 'fuseau' => 'Europe/Vienna'],
        'BE' => ['nom' => 'Belgique', 'devise' => 'EUR', 'fuseau' => 'Europe/Brussels'],
        'BG' => ['nom' => 'Bulgarie', 'devise' => 'BGN', 'fuseau' => 'Europe/Sofia'],
        'HR' => ['nom' => 'Croatie', 'devise' => 'EUR', 'fuseau' => 'Europe/Zagreb'],
        'CZ' => ['nom' => 'Tchéquie', 'devise' => 'CZK', 'fuseau' => 'Europe/Prague'],
        'DK' => ['nom' => 'Danemark', 'devise' => 'DKK', 'fuseau' => 'Europe/Copenhagen'],
        'EE' => ['nom' => 'Estonie', 'devise' => 'EUR', 'fuseau' => 'Europe/Tallinn'],
        'FI' => ['nom' => 'Finlande', 'devise' => 'EUR', 'fuseau' => 'Europe/Helsinki'],
        'DE' => ['nom' => 'Allemagne', 'devise' => 'EUR', 'fuseau' => 'Europe/Berlin'],
        'GR' => ['nom' => 'Grèce', 'devise' => 'EUR', 'fuseau' => 'Europe/Athens'],
        'HU' => ['nom' => 'Hongrie', 'devise' => 'HUF', 'fuseau' => 'Europe/Budapest'],
        'IS' => ['nom' => 'Islande', 'devise' => 'ISK', 'fuseau' => 'Atlantic/Reykjavik'],
        'IT' => ['nom' => 'Italie', 'devise' => 'EUR', 'fuseau' => 'Europe/Rome'],
        'LV' => ['nom' => 'Lettonie', 'devise' => 'EUR', 'fuseau' => 'Europe/Riga'],
        'LI' => ['nom' => 'Liechtenstein', 'devise' => 'CHF', 'fuseau' => 'Europe/Vaduz'],
        'LT' => ['nom' => 'Lituanie', 'devise' => 'EUR', 'fuseau' => 'Europe/Vilnius'],
        'LU' => ['nom' => 'Luxembourg', 'devise' => 'EUR', 'fuseau' => 'Europe/Luxembourg'],
        'MT' => ['nom' => 'Malte', 'devise' => 'EUR', 'fuseau' => 'Europe/Malta'],
        'NL' => ['nom' => 'Pays-Bas', 'devise' => 'EUR', 'fuseau' => 'Europe/Amsterdam'],
        'NO' => ['nom' => 'Norvège', 'devise' => 'NOK', 'fuseau' => 'Europe/Oslo'],
        'PL' => ['nom' => 'Pologne', 'devise' => 'PLN', 'fuseau' => 'Europe/Warsaw'],
        'PT' => ['nom' => 'Portugal', 'devise' => 'EUR', 'fuseau' => 'Europe/Lisbon'],
        'RO' => ['nom' => 'Roumanie', 'devise' => 'RON', 'fuseau' => 'Europe/Bucharest'],
        'SK' => ['nom' => 'Slovaquie', 'devise' => 'EUR', 'fuseau' => 'Europe/Bratislava'],
        'SI' => ['nom' => 'Slovénie', 'devise' => 'EUR', 'fuseau' => 'Europe/Ljubljana'],
        'ES' => ['nom' => 'Espagne', 'devise' => 'EUR', 'fuseau' => 'Europe/Madrid'],
        'SE' => ['nom' => 'Suède', 'devise' => 'SEK', 'fuseau' => 'Europe/Stockholm'],
        'CH' => ['nom' => 'Suisse', 'devise' => 'CHF', 'fuseau' => 'Europe/Zurich'],
        'GB' => ['nom' => 'Royaume-Uni', 'devise' => 'GBP', 'fuseau' => 'Europe/London'],
        'US' => ['nom' => 'États-Unis', 'devise' => 'USD', 'fuseau' => 'America/New_York'],
    ];

    /**
     * @return array{telephone: string, code_pays: string, indicatif: string, pays: string, devise: ?string, fuseau: ?string}|null
     */
    public static function resolve(string $telephone): ?array
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $numero = $util->parse($telephone, null);
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumber($numero)) {
            return null;
        }

        $codePays = $util->getRegionCodeForNumber($numero);
        if ($codePays === null) {
            return null;
        }

        $enrichi = self::ENRICHISSEMENT[$codePays] ?? null;

        return [
            'telephone' => $util->format($numero, PhoneNumberFormat::E164),
            'code_pays' => $codePays,
            'indicatif' => '+'.$numero->getCountryCode(),
            'pays' => $enrichi['nom'] ?? $codePays,
            'devise' => $enrichi['devise'] ?? null,
            'fuseau' => $enrichi['fuseau'] ?? null,
        ];
    }
}
