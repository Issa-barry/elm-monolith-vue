<?php

namespace App\Support;

/**
 * Normalise un montant saisi avec des séparateurs de milliers (espace normale,
 * espace fine insécable U+202F produite par `toLocaleString('fr-FR')`, virgule)
 * en nombre exploitable côté serveur. Défense en profondeur : le formulaire
 * (ex: Solde d'ouverture) envoie déjà la valeur brute au serveur (le formatage
 * n'est qu'un affichage côté client), mais toute autre source d'appel (API,
 * import, ancien client non mis à jour) doit aussi être tolérée sans lever une
 * erreur de validation "numeric" sur une chaîne comme "20 000 000".
 */
class MontantNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $nettoye = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value);
        $nettoye = str_replace(',', '.', $nettoye);

        return $nettoye === '' ? $value : $nettoye;
    }
}
