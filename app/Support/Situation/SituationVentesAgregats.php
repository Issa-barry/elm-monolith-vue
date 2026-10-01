<?php

namespace App\Support\Situation;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use Illuminate\Support\Collection;

/**
 * Agrégats partagés par les onglets Situation (fiche véhicule, fiche agent) : produits vendus et
 * situation des paiements. Chaque fiche choisit ses ventes (règles d'attribution et de date qui lui
 * sont propres) ; la manière de les résumer, elle, est unique.
 */
final class SituationVentesAgregats
{
    /**
     * Situation de paiement d'une vente = statut_facture de sa facture (recalculé à chaque
     * encaissement par FactureVente::recalculStatut() : aucun encaissement → impayée, encaissé
     * ≥ net → payée, sinon partiel). Une facture n'a qu'un statut : les catégories sont donc
     * mutuellement exclusives. « Créée » (aucun encaissement encore enregistré) et « impayée »
     * sont le même état financier — rien n'a été encaissé — et forment ensemble « Impayé ».
     */
    private const CATEGORIES_PAIEMENT = [
        'paye' => ['label' => 'Payé', 'statuts' => [StatutFactureVente::PAYEE]],
        'partiel' => ['label' => 'Partiel', 'statuts' => [StatutFactureVente::PARTIEL]],
        'impaye' => ['label' => 'Impayé', 'statuts' => [StatutFactureVente::CREEE, StatutFactureVente::IMPAYEE]],
    ];

    /**
     * Agrégé par variante (grain transactionnel réel), trié par quantité vendue décroissante.
     * « Quantité vendue » = quantite_livree si renseignée, sinon quantite_demandee — même repli que
     * CashbackService::quantiteEligible().
     *
     * @param  Collection<int, CommandeVenteLigne>  $lignes  de la vente la plus récente à la plus ancienne
     */
    public static function produitsVendus(Collection $lignes): array
    {
        return $lignes
            ->groupBy('variante_id')
            ->map(function (Collection $groupe) {
                // Le libellé affiché est celui de la vente la plus récente (snapshot figé à la vente,
                // jamais le nom courant), avec le même repli que CommandeVenteFormBuilder pour les
                // lignes antérieures aux snapshots.
                $derniere = $groupe->first();

                return [
                    'variante_id' => $derniere->variante_id,
                    'libelle' => $derniere->libelle_snapshot ?? $derniere->variante?->produit?->nom,
                    'quantite' => $groupe->sum(fn ($l) => (int) ($l->quantite_livree ?? $l->quantite_demandee)),
                    'montant' => (float) $groupe->sum('total_ligne'),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['quantite'], $b['montant']] <=> [$a['quantite'], $a['montant']])
            ->values()
            ->all();
    }

    /**
     * Chaque catégorie est valorisée au montant facturé (montant_net) de ses ventes : le total
     * des catégories redonne le facturé, sans double compte. La part non encaissée d'une vente
     * partielle reste dans « Partiel » (exposée à part dans reste_a_encaisser) — le « Reste à payer »
     * global = reste_a_encaisser de « Impayé » + celui de « Partiel ».
     *
     * Un seul pourcentage est exposé : la part du montant total facturé. Le nombre de ventes
     * reste informatif, sans pourcentage (décision produit du 19/09/2026).
     *
     * @param  Collection<int, FactureVente>  $factures  factures non annulées
     */
    public static function paiements(Collection $factures): array
    {
        $totalMontant = (float) $factures->sum(fn (FactureVente $f) => (float) $f->montant_net);
        $totalVentes = $factures->count();

        $repartition = [];
        foreach (self::CATEGORIES_PAIEMENT as $code => $categorie) {
            $groupe = $factures->filter(fn (FactureVente $f) => in_array($f->statut_facture, $categorie['statuts'], true));
            $montant = (float) $groupe->sum(fn (FactureVente $f) => (float) $f->montant_net);

            $repartition[] = [
                'code' => $code,
                'label' => $categorie['label'],
                'montant' => $montant,
                'pourcentage_montant' => self::pourcentage($montant, $totalMontant),
                'nb_ventes' => $groupe->count(),
                'reste_a_encaisser' => (float) $groupe->sum(fn (FactureVente $f) => $f->montant_restant),
            ];
        }

        return [
            'total_montant' => $totalMontant,
            'total_ventes' => $totalVentes,
            'repartition' => $repartition,
        ];
    }

    public static function pourcentage(float $part, float $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }
}
