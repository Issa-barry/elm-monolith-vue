<?php

namespace App\Support\Rh;

use Illuminate\Support\Str;

/**
 * Nationalités proposées dans le dossier salarié (docs/rh-employes-identite.md) : libellé français
 * au féminin (« Nationalité : Guinéenne »), code ISO 3166-1 du pays pour le drapeau. La valeur
 * enregistrée est le libellé, jamais le nom du pays ni le code. Seule source de cette liste : le
 * formulaire la reçoit du serveur, qui valide la valeur envoyée.
 */
final class Nationalites
{
    /** Proposée en tête de liste. */
    public const PAR_DEFAUT = 'GN';

    public const LISTE = [
        'AF' => 'Afghane', 'ZA' => 'Sud-africaine', 'AL' => 'Albanaise', 'DZ' => 'Algérienne',
        'DE' => 'Allemande', 'AD' => 'Andorrane', 'AO' => 'Angolaise', 'AG' => 'Antiguaise-et-barbudienne',
        'SA' => 'Saoudienne', 'AR' => 'Argentine', 'AM' => 'Arménienne', 'AU' => 'Australienne',
        'AT' => 'Autrichienne', 'AZ' => 'Azerbaïdjanaise', 'BS' => 'Bahaméenne', 'BH' => 'Bahreïnienne',
        'BD' => 'Bangladaise', 'BB' => 'Barbadienne', 'BE' => 'Belge', 'BZ' => 'Bélizienne',
        'BJ' => 'Béninoise', 'BT' => 'Bhoutanaise', 'BY' => 'Biélorusse', 'MM' => 'Birmane',
        'BO' => 'Bolivienne', 'BA' => 'Bosnienne', 'BW' => 'Botswanaise', 'BR' => 'Brésilienne',
        'BN' => 'Brunéienne', 'BG' => 'Bulgare', 'BF' => 'Burkinabè', 'BI' => 'Burundaise',
        'KH' => 'Cambodgienne', 'CM' => 'Camerounaise', 'CA' => 'Canadienne', 'CV' => 'Cap-verdienne',
        'CF' => 'Centrafricaine', 'CL' => 'Chilienne', 'CN' => 'Chinoise', 'CY' => 'Chypriote',
        'CO' => 'Colombienne', 'KM' => 'Comorienne', 'CG' => 'Congolaise (Congo)', 'CD' => 'Congolaise (RD Congo)',
        'KP' => 'Nord-coréenne', 'KR' => 'Sud-coréenne', 'CR' => 'Costaricienne', 'CI' => 'Ivoirienne',
        'HR' => 'Croate', 'CU' => 'Cubaine', 'DK' => 'Danoise', 'DJ' => 'Djiboutienne',
        'DM' => 'Dominiquaise', 'DO' => 'Dominicaine', 'EG' => 'Égyptienne', 'AE' => 'Émirienne',
        'EC' => 'Équatorienne', 'ER' => 'Érythréenne', 'ES' => 'Espagnole', 'EE' => 'Estonienne',
        'SZ' => 'Eswatinienne', 'US' => 'Américaine', 'ET' => 'Éthiopienne', 'FJ' => 'Fidjienne',
        'FI' => 'Finlandaise', 'FR' => 'Française', 'GA' => 'Gabonaise', 'GM' => 'Gambienne',
        'GE' => 'Géorgienne', 'GH' => 'Ghanéenne', 'GR' => 'Grecque', 'GD' => 'Grenadienne',
        'GT' => 'Guatémaltèque', 'GN' => 'Guinéenne', 'GW' => 'Bissau-guinéenne', 'GQ' => 'Équatoguinéenne',
        'GY' => 'Guyanienne', 'HT' => 'Haïtienne', 'HN' => 'Hondurienne', 'HU' => 'Hongroise',
        'IN' => 'Indienne', 'ID' => 'Indonésienne', 'IQ' => 'Irakienne', 'IR' => 'Iranienne',
        'IE' => 'Irlandaise', 'IS' => 'Islandaise', 'IL' => 'Israélienne', 'IT' => 'Italienne',
        'JM' => 'Jamaïcaine', 'JP' => 'Japonaise', 'JO' => 'Jordanienne', 'KZ' => 'Kazakhe',
        'KE' => 'Kényane', 'KG' => 'Kirghize', 'KI' => 'Kiribatienne', 'XK' => 'Kosovare',
        'KW' => 'Koweïtienne', 'LA' => 'Laotienne', 'LS' => 'Lesothane', 'LV' => 'Lettone',
        'LB' => 'Libanaise', 'LR' => 'Libérienne', 'LY' => 'Libyenne', 'LI' => 'Liechtensteinoise',
        'LT' => 'Lituanienne', 'LU' => 'Luxembourgeoise', 'MK' => 'Macédonienne', 'MG' => 'Malgache',
        'MY' => 'Malaisienne', 'MW' => 'Malawienne', 'MV' => 'Maldivienne', 'ML' => 'Malienne',
        'MT' => 'Maltaise', 'MA' => 'Marocaine', 'MH' => 'Marshallaise', 'MU' => 'Mauricienne',
        'MR' => 'Mauritanienne', 'MX' => 'Mexicaine', 'FM' => 'Micronésienne', 'MD' => 'Moldave',
        'MC' => 'Monégasque', 'MN' => 'Mongole', 'ME' => 'Monténégrine', 'MZ' => 'Mozambicaine',
        'NA' => 'Namibienne', 'NR' => 'Nauruane', 'NP' => 'Népalaise', 'NI' => 'Nicaraguayenne',
        'NE' => 'Nigérienne', 'NG' => 'Nigériane', 'NO' => 'Norvégienne', 'NZ' => 'Néo-zélandaise',
        'OM' => 'Omanaise', 'UG' => 'Ougandaise', 'UZ' => 'Ouzbèke', 'PK' => 'Pakistanaise',
        'PW' => 'Palaosienne', 'PS' => 'Palestinienne', 'PA' => 'Panaméenne', 'PG' => 'Papouane-néo-guinéenne',
        'PY' => 'Paraguayenne', 'NL' => 'Néerlandaise', 'PE' => 'Péruvienne', 'PH' => 'Philippine',
        'PL' => 'Polonaise', 'PT' => 'Portugaise', 'QA' => 'Qatarienne', 'RO' => 'Roumaine',
        'GB' => 'Britannique', 'RU' => 'Russe', 'RW' => 'Rwandaise', 'KN' => 'Kittitienne-et-névicienne',
        'SM' => 'Saint-marinaise', 'VC' => 'Vincentaise', 'LC' => 'Saint-lucienne', 'SB' => 'Salomonaise',
        'SV' => 'Salvadorienne', 'WS' => 'Samoane', 'ST' => 'Santoméenne', 'SN' => 'Sénégalaise',
        'RS' => 'Serbe', 'SC' => 'Seychelloise', 'SL' => 'Sierra-léonaise', 'SG' => 'Singapourienne',
        'SK' => 'Slovaque', 'SI' => 'Slovène', 'SO' => 'Somalienne', 'SD' => 'Soudanaise',
        'SS' => 'Sud-soudanaise', 'LK' => 'Sri-lankaise', 'SE' => 'Suédoise', 'CH' => 'Suisse',
        'SR' => 'Surinamaise', 'SY' => 'Syrienne', 'TJ' => 'Tadjike', 'TW' => 'Taïwanaise',
        'TZ' => 'Tanzanienne', 'TD' => 'Tchadienne', 'CZ' => 'Tchèque', 'TH' => 'Thaïlandaise',
        'TL' => 'Est-timoraise', 'TG' => 'Togolaise', 'TO' => 'Tongienne', 'TT' => 'Trinidadienne',
        'TN' => 'Tunisienne', 'TM' => 'Turkmène', 'TR' => 'Turque', 'TV' => 'Tuvaluane',
        'UA' => 'Ukrainienne', 'UY' => 'Uruguayenne', 'VU' => 'Vanuatuane', 'VA' => 'Vaticane',
        'VE' => 'Vénézuélienne', 'VN' => 'Vietnamienne', 'YE' => 'Yéménite', 'ZM' => 'Zambienne',
        'ZW' => 'Zimbabwéenne',
    ];

    /** @return list<string> */
    public static function libelles(): array
    {
        return array_values(self::LISTE);
    }

    /**
     * Options du sélecteur : la nationalité par défaut d'abord, puis l'ordre alphabétique (accents
     * ignorés). Une valeur enregistrée avant la liste et absente de celle-ci est ajoutée telle quelle
     * (sans drapeau) pour rester affichée et conservée.
     *
     * @return list<array{value: string, label: string, code: ?string}>
     */
    public static function options(?string $valeurActuelle = null): array
    {
        $autres = array_diff_key(self::LISTE, [self::PAR_DEFAUT => true]);
        uasort($autres, fn (string $a, string $b) => strcmp(Str::lower(Str::ascii($a)), Str::lower(Str::ascii($b))));

        $options = [['value' => self::LISTE[self::PAR_DEFAUT], 'label' => self::LISTE[self::PAR_DEFAUT], 'code' => self::PAR_DEFAUT]];
        foreach ($autres as $code => $libelle) {
            $options[] = ['value' => $libelle, 'label' => $libelle, 'code' => $code];
        }

        if (filled($valeurActuelle) && ! in_array($valeurActuelle, self::LISTE, true)) {
            $options[] = ['value' => $valeurActuelle, 'label' => $valeurActuelle, 'code' => null];
        }

        return $options;
    }
}
