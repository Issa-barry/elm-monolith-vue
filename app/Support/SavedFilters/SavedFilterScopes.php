<?php

namespace App\Support\SavedFilters;

use App\Enums\ProduitStatut;
use App\Enums\SiteType;
use App\Enums\StockStatut;
use App\Models\CashbackTransaction;
use App\Models\Produit;
use App\Models\User;
use App\Support\Commission\CommissionProcessusFilter;
use Illuminate\Validation\Rule;

/**
 * Seul endroit à modifier pour activer « Mes vues » sur une nouvelle liste (cf. docs/filters.md).
 * Le moteur (SavedFilterService) reste commun : stockage, partage, vue par défaut, agences.
 *
 * Par scope :
 * - authorize : [ability, modèle] ou callback reprenant l'autorisation de la page Index ;
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

        $commission = [
            'authorize' => fn (User $user): bool => $user->canReadCommissions(),
            'share' => 'commissions.update',
            'sites' => true,
            'criteria' => [
                'statut' => [Rule::in(['creee', 'impaye', 'partiel', 'paye'])],
                'periode' => ['string', 'regex:/^\d{4}-(0[1-9]|1[0-2])-(P1|P2|M)$/'],
                'processus' => ['array', 'max:4', Rule::in(array_column(CommissionProcessusFilter::options(), 'value'))],
            ],
        ];

        return [
            'commissions-livreurs' => $commission,
            'commissions-proprietaires' => [
                ...$commission,
                'criteria' => [...$commission['criteria'], 'nom' => $search, 'telephone' => $search],
            ],
            'commissions-sites' => [
                ...$commission,
                'criteria' => [...$commission['criteria'], 'categorie_id' => $categorie, 'site_type' => [Rule::enum(SiteType::class)]],
            ],
            'commissions-consultants' => [
                ...$commission,
                'sites' => false,
                'criteria' => [
                    ...$commission['criteria'],
                    'consultant_id' => ['ulid', Rule::exists('prestataires', 'id')->where('organization_id', $org)],
                ],
            ],
            'cashback' => [
                'authorize' => ['viewAny', CashbackTransaction::class],
                'share' => 'cashback.update',
                'sites' => false,
                'criteria' => [
                    'statut' => [Rule::in(['en_attente', 'valide', 'partiel', 'verse'])],
                    'client_id' => ['ulid', Rule::exists('clients', 'id')->where('organization_id', $org)],
                    'date_debut' => ['date_format:Y-m-d'],
                    'date_fin' => ['date_format:Y-m-d'],
                ],
            ],
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
