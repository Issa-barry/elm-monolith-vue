<?php

namespace App\Services\ImportFlotte\Normalizers;

use App\Models\Categorie;

/**
 * Détecte, dans une ligne d'en-têtes de feuille Excel, les colonnes de capacité dynamiques
 * au format "{prefixe}<REFERENCE>" (ex: "capacite__BOUTEILLE_DEAU") et les résout contre la
 * Categorie du catalogue produit de l'organisation portant cette `reference` exacte —
 * extrait d'ImportFlotteParser::resoudreColonnesCapacite() pour être réutilisé tel quel par
 * ImportVehiculesMajParser (même convention de gabarit "vehicules"), sans dupliquer cette
 * logique ni faire dépendre un parseur de l'autre. Comportement strictement identique à
 * l'original : ImportFlotteParser délègue désormais ici.
 *
 * Une organisation qui n'a pas (encore) de catégorie portant cette référence obtient
 * simplement `categorie: null` pour cette colonne — c'est à l'appelant de décider si une
 * ligne qui saisit effectivement une valeur sur cette colonne doit être bloquée. Un VRAI
 * doublon de colonne (même référence normalisée présente deux fois) retourne une erreur
 * globale, la résolution étant sinon ambiguë.
 */
class CapaciteColonneResolver
{
    /**
     * @return array{colonnes: array<int, array{cle: string, reference: string, categorie: ?Categorie}>, erreur_doublon: ?string}
     */
    public static function resoudre(array $entetes, string $orgId, string $prefixe = 'capacite__'): array
    {
        $longueurPrefixe = mb_strlen($prefixe);

        $colonnes = [];
        $referencesVues = [];
        $referencesEnDoublon = [];

        foreach ($entetes as $entete) {
            if (mb_strtolower(mb_substr($entete, 0, $longueurPrefixe)) !== $prefixe) {
                continue;
            }

            $reference = mb_strtoupper(trim(mb_substr($entete, $longueurPrefixe)), 'UTF-8');
            if ($reference === '') {
                continue;
            }

            if (isset($referencesVues[$reference])) {
                $referencesEnDoublon[$reference] = true;
            }
            $referencesVues[$reference] = true;

            $colonnes[] = ['cle' => $entete, 'reference' => $reference];
        }

        if (! empty($referencesEnDoublon)) {
            return [
                'colonnes' => [],
                'erreur_doublon' => 'Colonnes de capacité en doublon pour la référence '
                    .implode(', ', array_map(fn ($r) => "\"{$r}\"", array_keys($referencesEnDoublon)))
                    .' — une seule colonne "'.$prefixe.'<REFERENCE>" par catégorie est autorisée.',
            ];
        }

        $categoriesParReference = Categorie::where('organization_id', $orgId)
            ->whereIn('reference', array_column($colonnes, 'reference'))
            ->get()
            ->keyBy('reference');

        foreach ($colonnes as &$colonne) {
            $colonne['categorie'] = $categoriesParReference->get($colonne['reference']);
        }
        unset($colonne);

        return ['colonnes' => $colonnes, 'erreur_doublon' => null];
    }
}
