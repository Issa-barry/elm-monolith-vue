<?php

namespace App\Support\SavedFilters;

use App\Enums\ProduitStatut;
use App\Enums\StockStatut;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Seul endroit à modifier pour activer « Mes vues » sur une nouvelle liste (cf. docs/filters.md).
 * Le moteur (SavedFilterService) reste commun : stockage, partage, vue par défaut, agences.
 *
 * Par scope :
 * - authorize : [ability, modèle] exigé pour lire/écrire les vues (celui de la page Index) ;
 * - share     : permission requise pour publier une vue partagée ;
 * - sites     : la liste accepte le filtre Agence (site_ids + « mes agences ») ;
 * - criteria  : critères propres à la liste => règles de validation de leur valeur.
 */
final class SavedFilterScopes
{
    public static function all(User $user): array
    {
        $org = $user->organization_id;
        $search = ['string', 'max:255'];
        $categorie = ['ulid', Rule::exists('categories', 'id')->where('organization_id', $org)];

        return [
            'produits' => [
                'authorize' => ['viewAny', Produit::class],
                'share' => 'produits.update',
                'sites' => true,
                'criteria' => [
                    'search' => $search,
                    'produit_type_id' => ['ulid', Rule::exists('produit_types', 'id')->where('organization_id', $org)],
                    'statut' => [Rule::enum(ProduitStatut::class)],
                    'categorie_id' => $categorie,
                    'stock' => [Rule::in(['rupture', 'stock_faible'])],
                ],
            ],
            'stock' => [
                'authorize' => ['viewAny', Produit::class],
                'share' => 'produits.update',
                'sites' => true,
                'criteria' => [
                    'search' => $search,
                    'categorie_id' => $categorie,
                    'stock_statut' => [Rule::enum(StockStatut::class)],
                ],
            ],
        ];
    }
}
