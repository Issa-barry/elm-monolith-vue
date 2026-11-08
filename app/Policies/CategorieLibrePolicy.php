<?php

namespace App\Policies;

use App\Models\User;

/**
 * Écran « Catégories de contacts » (ADR 0060, lot 6a) : consultation et administration réservées à
 * `contacts.gerer_categories` (écran d'administration). L'organisation de chaque élément est
 * vérifiée par App\Services\Contacts\ReferentielCategories.
 */
class CategorieLibrePolicy
{
    public function gererCategories(User $user): bool
    {
        return $user->can('contacts.gerer_categories');
    }
}
