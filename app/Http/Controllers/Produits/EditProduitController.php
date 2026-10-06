<?php

namespace App\Http\Controllers\Produits;

use App\Enums\ProduitStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Services\ProduitSeuilAlerteService;
use App\Support\Produits\ProduitFormOptions;
use App\Support\Produits\ProduitVarianteOptionsFormatter;
use Inertia\Inertia;
use Inertia\Response;

class EditProduitController extends Controller
{
    public function __construct(
        private readonly ProduitSeuilAlerteService $seuilAlerteService,
    ) {}

    public function __invoke(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        $produit->load(['variantes.valeurs.option', 'medias']);
        $variantePrincipale = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $orgId = $produit->organization_id;

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie_id' => $produit->categorie_id,
                'fournisseur_id' => $produit->fournisseur_id,
                'sku' => $variantePrincipale?->sku,
                'code_barres' => $variantePrincipale?->code_barres,
                'image_url' => $produit->image_url,
                'produit_type_id' => $produit->produit_type_id,
                'statut' => $produit->statut?->value,
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_usine_tricycle' => $variantePrincipale?->prix_usine_tricycle,
                'prix_externe' => $variantePrincipale?->prix_externe,
                'prix_revendeur' => $variantePrincipale?->prix_revendeur,
                'prix_distributeur' => $variantePrincipale?->prix_distributeur,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'description' => $produit->description,
                'has_variantes' => $produit->variantes->count() > 1,
                'variantes_count' => $produit->variantes->count(),
                'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                    'id' => $v->id,
                    'libelle' => $v->libelle,
                    'sku' => $v->sku,
                    'code_barres' => $v->code_barres,
                    'prix_usine' => $v->prix_usine,
                    'prix_usine_tricycle' => $v->prix_usine_tricycle,
                    'prix_vente' => $v->prix_vente,
                    'prix_achat' => $v->prix_achat,
                    'cout' => $v->cout,
                    'is_default' => $v->is_default,
                    'is_active' => $v->is_active,
                    'options' => ProduitVarianteOptionsFormatter::pour($v),
                ]),
                'medias' => $produit->medias->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => $m->url,
                    'thumb_url' => $m->thumb_url,
                    'is_primary' => $m->is_primary,
                    'position' => $m->position,
                ]),
            ],
            'types' => ProduitFormOptions::types($orgId),
            'statuts' => ProduitStatut::options(),
            'categories' => Categorie::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'parent_id']),
            'fournisseurs' => ProduitFormOptions::fournisseurs($orgId),
            'limites' => ProduitFormOptions::limites($orgId),
            'seuilOrganisationDefaut' => Parametre::getSeuilStockFaible($orgId),
            // Sites ACTIFS de l'organisation + seuils spécifiques déjà enregistrés pour ce
            // produit — alimente la section "Alerte de stock faible" par agence de
            // ProduitForm.vue. Un site désactivé après coup n'apparaît plus ici, mais ses
            // éventuelles lignes produit_seuils_alerte restent en base (pas de suppression
            // arbitraire de données historiques).
            'sites' => Site::where('organization_id', $orgId)->actives()->orderBy('nom')->get(['id', 'nom', 'code', 'type'])
                ->map(fn (Site $s) => ['id' => $s->id, 'code' => $s->code, 'label' => $s->label]),
            // Disponibilité ET alerte, indexées par site_id (cf. ProduitSeuilAlerteService::
            // pourProduit()) — un site absent de cette collection est disponible (défaut TRUE)
            // mais sans alerte (défaut FALSE), cf. StockStatutService.
            'seuilsAlerteSite' => $this->seuilAlerteService->pourProduit($produit),
        ]);
    }
}
