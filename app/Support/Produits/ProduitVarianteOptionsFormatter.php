<?php

namespace App\Support\Produits;

use App\Models\ProduitVariante;

/**
 * Décompose une variante en paires {option, valeur} triées par position d'option puis de
 * valeur — permet au frontend de regrouper les variantes par n'importe laquelle de leurs
 * options (pattern "Regrouper par" façon Shopify) sans requête supplémentaire. Nécessite
 * variantes.valeurs.option pré-chargé par l'appelant (évite le N+1).
 *
 * Extrait de ProduitController::varianteOptions() (jusqu'ici dupliqué par appel entre show(),
 * edit() et l'éditeur de variantes) pour que ces pages restent garanties d'afficher exactement
 * les mêmes données, dans le même ordre.
 */
final class ProduitVarianteOptionsFormatter
{
    /** @return array<int, array{option: string, valeur: string}> */
    public static function pour(ProduitVariante $variante): array
    {
        $valeurs = $variante->valeurs->all();
        usort($valeurs, fn ($a, $b) => [$a->option->position, $a->position] <=> [$b->option->position, $b->position]);

        return array_map(fn ($v) => ['option' => $v->option->nom, 'valeur' => $v->valeur], $valeurs);
    }
}
