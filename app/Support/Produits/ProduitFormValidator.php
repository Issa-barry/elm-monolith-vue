<?php

namespace App\Support\Produits;

use App\Enums\ProduitStatut;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Validation partagée create/update — extrait de ProduitController::validerFormulaire(). Le prix
 * requis selon le type est vérifié par ProduitService::validerPrixSelonType() (appelé par le
 * service, pas ici) pour rester l'unique point de vérité partagé avec l'API.
 */
final class ProduitFormValidator
{
    public static function valider(Request $request, ?Produit $produit = null): array
    {
        $orgId = auth()->user()->organization_id;
        // Variante par défaut du produit édité — exclue de la contrainte d'unicité ci-dessous
        // (sinon une mise à jour qui ne touche pas au code-barres se rejetterait elle-même).
        $varianteId = $produit?->variantePrincipale()->first()?->id;

        return $request->validate([
            'nom' => 'required|string|max:255',
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'fournisseur_id' => [
                'nullable',
                Rule::exists('fournisseurs', 'id')->where('organization_id', $orgId),
            ],
            'code_barres' => [
                'nullable', 'string', 'max:100',
                Rule::unique('produit_variantes', 'code_barres')
                    ->where('organization_id', $orgId)
                    ->ignore($varianteId),
            ],
            'produit_type_id' => [
                'required',
                Rule::exists('produit_types', 'id')->where('organization_id', $orgId)->where('statut', 'actif'),
            ],
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_usine_tricycle' => 'nullable|integer|min:0',
            // Tarifs par nature de client — n'ont de sens que pour un produit fabricable ;
            // ProduitService::nettoyerPrixNatureSiNonFabricable() les ignore silencieusement
            // pour tout autre type plutôt que de les rejeter ici (pas de dépendance entre
            // champs dans cette validation Laravel, cf. docblock de cette classe).
            'prix_externe' => 'nullable|integer|min:0',
            'prix_revendeur' => 'nullable|integer|min:0',
            'prix_distributeur' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            // L'activation ("être alerté ?") ET le seuil ne sont plus configurés au niveau
            // produit (anciennes colonnes alerte_stock_active/seuil_alerte_stock, conservées en
            // base à titre historique mais plus jamais écrites ici) : ils se règlent désormais
            // PAR SITE, cf. ProduitSeuilAlerteService — absent ou vide à la création, un produit
            // n'ayant pas encore d'id ne peut pas porter de configuration spécifique par site.
            'seuils_site' => 'nullable|array',
            'seuils_site.*.site_id' => ['required_with:seuils_site', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'seuils_site.*.actif' => ['sometimes', 'boolean'],
            'seuils_site.*.seuil' => ['nullable', 'integer', 'min:1'],
            // Disponibilité — notion INDÉPENDANTE de l'alerte (cf. ProduitSeuilAlerteService::
            // definirDisponibilitePourSites()) : "tous" = disponible partout (défaut) ; "selection"
            // = seuls les sites listés dans sites_disponibles le sont, tous les autres deviennent
            // explicitement indisponibles.
            'disponibilite_mode' => ['nullable', 'in:tous,selection'],
            'sites_disponibles' => ['nullable', 'array'],
            'sites_disponibles.*' => [Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
            // Optionnel : déclinaisons (couleur/taille...). Absent/vide = produit simple
            // (variante par défaut invisible). Consommé par ProduitService::creer().
            'options' => 'nullable|array',
            'options.*.nom' => 'required_with:options|string|max:100',
            'options.*.valeurs' => 'required_with:options|array|min:1',
            'options.*.valeurs.*' => 'required|string|max:100',
            // Rattachement optionnel au catalogue d'options réutilisables — purement informatif
            // pour l'enrichissement du catalogue, cf. VarianteService::enrichirCatalogue().
            'options.*.option_catalogue_id' => ['nullable', Rule::exists('option_catalogues', 'id')->where('organization_id', $orgId)],
        ]);
    }
}
