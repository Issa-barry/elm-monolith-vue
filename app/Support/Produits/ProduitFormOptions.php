<?php

namespace App\Support\Produits;

use App\Models\Fournisseur;
use App\Models\Parametre;
use App\Models\ProduitType;
use Illuminate\Support\Collection;

/**
 * Options de formulaire (types/fournisseurs/limites catalogue) communes aux pages Index/Create/
 * Edit/Show du module Produits — extrait de ProduitController::typesOptions()/fournisseursOptions()/
 * limitesCatalogue(), partagé à l'identique par plusieurs actions.
 */
final class ProduitFormOptions
{
    /**
     * Types actifs de l'organisation, pour peupler les sélecteurs Create/Edit/filtre — remplace
     * l'ancien App\Enums\ProduitType::options() figé, désormais un vrai CRUD par organisation
     * (cf. App\Http\Controllers\Produits\Types\*).
     */
    public static function types(string $orgId): Collection
    {
        return ProduitType::where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->orderBy('position')->orderBy('nom')
            ->get(['id', 'nom', 'code', 'gere_stock', 'vendable', 'achetable', 'prix_achat_requis', 'prix_usine_requis', 'prix_vente_requis'])
            ->map(fn (ProduitType $t) => [
                'value' => $t->id,
                'label' => $t->nom,
                'gere_stock' => $t->gere_stock,
                'required_prices' => $t->requiredPrices(),
                // Applicabilité fonctionnelle (déjà utilisée pour filtrer les flux
                // achat/vente, cf. CommandeAchatController/CommandeVenteFormBuilder/
                // Ventes\IndexPdvController) — réutilisée ici pour piloter la visibilité des champs
                // prix_achat/prix_vente dans le formulaire, distincte de l'obligation de
                // saisie (*_requis, cf. required_prices ci-dessus).
                'achetable' => $t->achetable,
                'vendable' => $t->vendable,
                // Repère technique stable (cf. ProduitType docblock) — pilote la visibilité de
                // la section "Tarification clients" (prix_externe/revendeur/distributeur) dans
                // ProduitForm.vue, réservée au type fabricable.
                'code' => $t->code,
            ]);
    }

    /**
     * Fournisseurs actifs de l'organisation, pour peupler FournisseurSelect.vue — préchargés
     * en une fois (comme categories/optionsCatalogue) plutôt qu'en recherche distante : le
     * volume attendu (prestataires d'une PME) ne justifie pas une pagination/API dédiée.
     */
    public static function fournisseurs(string $orgId): Collection
    {
        // raison_sociale/nom ne sont pas des colonnes de fournisseurs (déléguées à
        // Personne/EntrepriseTierce, cf. Fournisseur::getNomCompletAttribute()) — le tri
        // se fait donc en PHP sur l'accesseur, pas via orderBy() côté SQL.
        return Fournisseur::where('organization_id', $orgId)
            ->where('is_active', true)
            ->with(['personne', 'entrepriseTierce'])
            ->get()
            ->sortBy('nom_complet')
            ->map(fn (Fournisseur $f) => [
                'id' => $f->id,
                'nom_complet' => $f->nom_complet,
                'phone' => $f->phone,
            ])
            ->values();
    }

    public static function limites(string $orgId): array
    {
        return [
            'max_photos_produit' => Parametre::getMaxPhotosProduit($orgId),
            'max_options_produit' => Parametre::getMaxOptionsProduit($orgId),
            'max_valeurs_option' => Parametre::getMaxValeursOption($orgId),
            'max_variantes_produit' => Parametre::getMaxVariantesProduit($orgId),
        ];
    }
}
