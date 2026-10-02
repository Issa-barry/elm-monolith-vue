<?php

namespace App\Http\Resources\Api;

use App\Models\Parametre;
use App\Services\StockStatutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    /**
     * Site par défaut de l'utilisateur authentifié (ou son premier site s'il n'a pas de site par
     * défaut), null s'il n'a aucun site — utilisé par seuilAlerteEffectifPourRequete() et
     * alerteActivePourRequete() ci-dessous, l'API n'ayant pas de contexte de site explicite par
     * requête (cf. docs/stock-alertes.md).
     */
    private function sitePourRequete(Request $request): ?object
    {
        $user = $request->user();

        return $user?->sites()->wherePivot('is_default', true)->first() ?? $user?->sites()->first();
    }

    /**
     * Seuil effectif pour le site par défaut de l'utilisateur authentifié — repli sur le seuil
     * global de l'organisation si aucun site n'est associé à l'utilisateur.
     */
    private function seuilAlerteEffectifPourRequete(Request $request): int
    {
        $site = $this->sitePourRequete($request);

        if ($site === null) {
            return Parametre::getSeuilStockFaible((string) $this->organization_id);
        }

        return app(StockStatutService::class)->seuilEffectifPourSite($this->resource, (string) $site->id);
    }

    /**
     * Alerte de stock faible activée pour le site par défaut de l'utilisateur authentifié — la
     * configuration se règle désormais PAR SITE (cf. ProduitSeuilAlerteService, 01/09/2026) :
     * false si l'utilisateur n'a aucun site (aucune configuration résolvable, jamais active par
     * défaut), même convention que seuilAlerteEffectifPourRequete() ci-dessus. Requiert AUSSI la
     * disponibilité (cf. StockStatutService::disponiblePourSite(), 02/09/2026) : un site non
     * disponible n'a jamais d'alerte active, quelle que soit la configuration `actif` en base.
     */
    private function alerteActivePourRequete(Request $request): bool
    {
        $site = $this->sitePourRequete($request);

        if ($site === null) {
            return false;
        }

        $stockStatutService = app(StockStatutService::class);

        return $stockStatutService->disponiblePourSite($this->resource, (string) $site->id)
            && $stockStatutService->alerteActivePourSite($this->resource, (string) $site->id);
    }

    public function toArray(Request $request): array
    {
        $variante = $this->relationLoaded('variantes')
            ? $this->variantes->firstWhere('is_default', true) ?? $this->variantes->first()
            : $this->variantePrincipale()->first();

        $type = $this->relationLoaded('produitType') ? $this->produitType : $this->produitType()->first();

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'categorie_id' => $this->categorie_id,
            'categorie_nom' => $this->whenLoaded('categorie', fn () => $this->categorie?->nom),
            'fournisseur_id' => $this->fournisseur_id,
            'fournisseur_nom' => $this->whenLoaded('fournisseur', fn () => $this->fournisseur?->nom_complet),
            'sku' => $variante?->sku,
            'code_barres' => $variante?->code_barres,
            'produit_type_id' => $this->produit_type_id,
            'type_nom' => $type?->nom,
            'type_gere_stock' => $type?->gere_stock ?? true,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'prix_usine' => $variante?->prix_usine,
            'prix_usine_tricycle' => $variante?->prix_usine_tricycle,
            'prix_externe' => $variante?->prix_externe,
            'prix_revendeur' => $variante?->prix_revendeur,
            'prix_distributeur' => $variante?->prix_distributeur,
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'cout' => $variante?->cout,
            'qte_stock' => $this->qte_stock,
            // Résolus pour le site PAR DÉFAUT de l'utilisateur qui consulte l'API (même
            // résolution que ProduitController@ajusterStock côté API) — repli sur le seuil
            // global / false si l'utilisateur n'a aucun site. L'activation ET le seuil se
            // règlent désormais par site (cf. ProduitSeuilAlerteService) : sans site précis,
            // aucune valeur unique n'a de sens pour tous les sites de l'organisation.
            'alerte_stock_active' => $this->alerteActivePourRequete($request),
            'seuil_alerte_effectif' => $this->seuilAlerteEffectifPourRequete($request),
            'description' => $this->description,
            'image_url' => $this->image_url,
            'in_stock' => $this->in_stock,
            'is_low_stock' => $this->is_low_stock,
            'is_out_of_stock' => $this->is_out_of_stock,
            'is_used' => $this->is_used,
            'has_variantes' => $this->relationLoaded('variantes') ? $this->variantes->count() > 1 : $this->variantes()->count() > 1,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
