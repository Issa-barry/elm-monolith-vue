<?php

namespace App\Support\SavedFilters;

use App\Enums\NatureMouvementFonds;
use App\Enums\ProduitStatut;
use App\Enums\SiteType;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Enums\StatutMouvementFonds;
use App\Enums\StatutSupportTresorerie;
use App\Enums\StockStatut;
use App\Enums\TypeSupportTresorerie;
use App\Models\CashbackTransaction;
use App\Models\CommandeVente;
use App\Models\MouvementFonds;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
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
            'mouvements-fonds' => [
                'authorize' => ['viewAny', MouvementFonds::class],
                'share' => 'tresorerie.update',
                'sites' => true,
                'criteria' => [
                    'statut' => [Rule::enum(StatutMouvementFonds::class)],
                    'search' => $search,
                    'nature' => [Rule::enum(NatureMouvementFonds::class)],
                    'caisse_id' => ['ulid', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $org)],
                    'caisse_role' => [Rule::in(['origine', 'destination'])],
                    'site_origine_id' => ['ulid', Rule::exists('sites', 'id')->where('organization_id', $org)],
                    'site_destination_id' => ['ulid', Rule::exists('sites', 'id')->where('organization_id', $org)],
                    'montant_min' => ['numeric', 'min:0'],
                    'montant_max' => ['numeric', 'min:0'],
                ],
            ],
            'tresorerie-supports' => [
                'authorize' => fn (User $user): bool => $user->can('tresorerie.read') || $user->can('tresorerie.gerer_soldes_ouverture'),
                'share' => 'tresorerie.gerer_soldes_ouverture',
                'sites' => true,
                'criteria' => [
                    'statut' => [Rule::enum(StatutSupportTresorerie::class)],
                    'type' => [Rule::enum(TypeSupportTresorerie::class)],
                    'nature' => [Rule::in(['agence', 'dediee'])],
                    'agent_id' => ['ulid', Rule::exists('users', 'id')->where('organization_id', $org)],
                ],
            ],
            'ventes' => [
                'authorize' => ['viewAny', CommandeVente::class],
                'share' => 'ventes.update',
                'sites' => true,
                'criteria' => [
                    'periode' => [Rule::in(['all', 'today', 'week', 'month'])],
                    'statuts' => ['array', Rule::in(array_column(StatutCommandeVente::cases(), 'value'))],
                    'statut_facture' => [Rule::enum(StatutFactureVente::class)],
                    'statut_commission' => [Rule::enum(StatutCommission::class)],
                    'date_debut' => ['date_format:Y-m-d'],
                    'date_fin' => ['date_format:Y-m-d'],
                    'vehicule' => $search,
                    'proprietaire' => $search,
                    'livreur' => $search,
                    'client' => $search,
                    'numero_commande' => $search,
                ],
            ],
            // Page Précommandes (ADR 0019) : mêmes critères que la liste Ventes, plus le retard.
            // Scope distinct : une vue de ventes ne s'applique jamais aux précommandes, ni l'inverse.
            'precommandes' => [
                'authorize' => ['viewAny', CommandeVente::class],
                'share' => 'ventes.update',
                'sites' => true,
                'criteria' => [
                    'statuts' => ['array', Rule::in(array_column(StatutCommandeVente::cases(), 'value'))],
                    'en_retard' => [Rule::in(['1'])],
                    'statut_facture' => [Rule::enum(StatutFactureVente::class)],
                    'statut_commission' => [Rule::enum(StatutCommission::class)],
                    'date_debut' => ['date_format:Y-m-d'],
                    'date_fin' => ['date_format:Y-m-d'],
                    'vehicule' => $search,
                    'proprietaire' => $search,
                    'livreur' => $search,
                    'client' => $search,
                    'numero_commande' => $search,
                ],
            ],
            'factures' => [
                'authorize' => ['viewAny', CommandeVente::class],
                'share' => 'ventes.update',
                'sites' => true,
                'criteria' => [
                    'periode' => [Rule::in(['today', 'week', 'month', 'tout'])],
                    'statut' => [Rule::enum(StatutFactureVente::class)],
                    'livreur_id' => ['ulid', Rule::exists('livreurs', 'id')->where('organization_id', $org)],
                    'vehicule' => $search,
                    'chauffeur' => $search,
                    'convoyeur' => $search,
                    'proprietaire' => $search,
                    'client' => $search,
                    'reference' => $search,
                ],
            ],
            'vehicules' => [
                'authorize' => ['viewAny', Vehicule::class],
                'share' => 'vehicules.update',
                'sites' => true,
                'criteria' => [
                    'nom' => $search,
                    'statut' => [Rule::in(['actif', 'inactif'])],
                    'type_vehicule_id' => ['ulid', Rule::exists('type_vehicules', 'id')->where('organization_id', $org)],
                    'usage' => [Rule::in(['vente', 'logistique', 'grossiste', 'aucun'])],
                    'agence_proprietaire_id' => ['string', function (string $attribute, mixed $value, \Closure $fail) use ($org): void {
                        if ($value !== '__none__' && ! Site::where('organization_id', $org)->whereKey($value)->exists()) {
                            $fail('Cette agence est indisponible.');
                        }
                    }],
                    'partage' => [Rule::in(['a_faire', 'fait'])],
                ],
            ],
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
