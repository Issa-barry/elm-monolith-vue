<?php

namespace App\Support\Produits;

use App\Models\Produit;

/**
 * Instantané et différentiel d'un produit pour l'audit trail — extrait de
 * ProduitController::produitSnapshot()/produitDiff(), partagé entre store/update/destroy
 * (snapshot) et update (diff, pour ne journaliser que les champs réellement modifiés).
 */
final class ProduitAuditSnapshot
{
    public static function pour(Produit $produit): array
    {
        $variante = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();

        return array_filter([
            'nom' => $produit->nom,
            'type' => $produit->produitType?->nom,
            'statut' => $produit->statut?->label(),
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'prix_usine' => $variante?->prix_usine,
            'prix_usine_tricycle' => $variante?->prix_usine_tricycle,
            'cout' => $variante?->cout,
            'qte_stock' => $produit->qte_stock,
            'description' => $produit->description,
            'code_barres' => $variante?->code_barres,
            'fournisseur' => $produit->fournisseur?->nom_complet,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public static function diff(array $before, array $after): array
    {
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $oldDiff = [];
        $newDiff = [];

        $normalize = fn ($v) => is_numeric($v) ? rtrim(number_format((float) $v, 2, '.', ''), '0') : (string) ($v ?? '');

        foreach ($allKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;
            if ($normalize($oldVal) !== $normalize($newVal)) {
                $oldDiff[$key] = $oldVal;
                $newDiff[$key] = $newVal;
            }
        }

        return [empty($oldDiff) ? null : $oldDiff, empty($newDiff) ? null : $newDiff];
    }
}
