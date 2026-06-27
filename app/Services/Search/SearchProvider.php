<?php

namespace App\Services\Search;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contrat commun à tous les fournisseurs de recherche globale. Chaque
 * implémentation porte sa propre règle de visibilité (organisation, site,
 * propriété des données) — la sécurité est décidée ici, pas dans le
 * contrôleur, pour qu'elle ne puisse pas être oubliée par un nouvel appelant.
 */
interface SearchProvider
{
    /**
     * Clé stable utilisée comme clé de regroupement dans la réponse JSON
     * (ex: "clients", "commandes").
     */
    public function key(): string;

    /**
     * Libellé affichable du groupe (ex: "Clients").
     */
    public function label(): string;

    /**
     * Cet utilisateur a-t-il le droit de voir cette catégorie de résultats,
     * même vide ? Si false, la clé n'apparaît pas du tout dans la réponse.
     */
    public function authorize(User $user): bool;

    /**
     * @return Collection<int, SearchResultItem>
     */
    public function search(string $query, User $user, int $limit): Collection;
}
