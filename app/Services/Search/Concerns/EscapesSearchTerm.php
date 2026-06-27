<?php

namespace App\Services\Search\Concerns;

trait EscapesSearchTerm
{
    /**
     * Échappe les caractères spéciaux LIKE (% et _) pour qu'un terme tapé par
     * l'utilisateur ne soit jamais interprété comme un joker SQL.
     */
    private function likeTerm(string $query): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        return '%'.$escaped.'%';
    }
}
