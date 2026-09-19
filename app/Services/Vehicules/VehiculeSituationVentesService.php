<?php

namespace App\Services\Vehicules;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Vehicule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Section « Activité commerciale » de l'onglet Situation (Vehicules/Show). Synthèse uniquement
 * (KPI, produits vendus, situation des paiements) : le détail transaction par transaction reste
 * sur l'écran Ventes. Ne réimplémente aucun calcul financier : réutilise FactureVente::
 * montant_encaisse / montant_restant / statut_facture (mêmes formules que
 * IndexCommandeVenteController).
 *
 * « Vendu » = commande ayant dépassé le stade brouillon et non annulée (décision produit du
 * 15/09/2026, cf. docs/vehicule-situation-ventes.md) — une commande encore en brouillon n'a
 * rien vendu, une commande annulée non plus.
 *
 * « Quantité vendue » = quantite_livree si renseignée, sinon quantite_demandee — même repli
 * que CashbackService::quantiteEligible() pour une vente sans étape de chargement/livraison
 * (comptoir), volontairement réutilisé ici plutôt qu'une nouvelle colonne quantite_vendue.
 */
class VehiculeSituationVentesService
{
    /**
     * Situation de paiement d'une vente = statut_facture de sa facture (recalculé à chaque
     * encaissement par FactureVente::recalculStatut() : aucun encaissement → impayée, encaissé
     * ≥ net → payée, sinon partiel). Une facture n'a qu'un statut : les catégories sont donc
     * mutuellement exclusives. « Créée » (aucun encaissement encore enregistré) et « impayée »
     * sont le même état financier — rien n'a été encaissé — et forment ensemble « Dû ».
     */
    private const CATEGORIES_PAIEMENT = [
        'paye' => ['label' => 'Payé', 'statuts' => [StatutFactureVente::PAYEE]],
        'partiel' => ['label' => 'Partiel', 'statuts' => [StatutFactureVente::PARTIEL]],
        'du' => ['label' => 'Dû', 'statuts' => [StatutFactureVente::CREEE, StatutFactureVente::IMPAYEE]],
    ];

    /**
     * @return array{kpis: array, produits: array, paiements: array, periode_debut: ?string, periode_fin: ?string}
     */
    public function pourVehicule(Vehicule $vehicule, string $periode = 'all'): array
    {
        [$debut, $fin] = $this->bornesPeriode($periode);

        $query = CommandeVente::where('vehicule_id', $vehicule->id)
            ->whereNotIn('statut', [StatutCommandeVente::BROUILLON->value, StatutCommandeVente::ANNULEE->value])
            ->with(['lignes.variante.produit', 'facture.encaissements'])
            ->orderByDesc('created_at');

        if ($debut && $fin) {
            $query->whereBetween('created_at', [$debut, $fin]);
        }

        $ventes = $query->get();
        $factures = $this->facturesActives($ventes);

        return [
            'kpis' => $this->kpis($ventes, $factures),
            'produits' => $this->produitsVendus($ventes),
            'paiements' => $this->paiements($factures),
            'periode_debut' => $debut?->toDateString(),
            'periode_fin' => $fin?->toDateString(),
        ];
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function bornesPeriode(string $periode): array
    {
        return match ($periode) {
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };
    }

    /**
     * Factures non annulées des ventes — même exclusion que l'écran Ventes pour « à encaisser » /
     * « déjà payé ».
     *
     * @param  Collection<int, CommandeVente>  $ventes
     * @return Collection<int, FactureVente>
     */
    private function facturesActives(Collection $ventes): Collection
    {
        return $ventes
            ->map(fn (CommandeVente $v) => $v->facture)
            ->filter(fn (?FactureVente $f) => $f !== null && ! $f->isAnnulee())
            ->values();
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     * @param  Collection<int, FactureVente>  $factures
     */
    private function kpis(Collection $ventes, Collection $factures): array
    {
        return [
            'ca_vendu' => (float) $ventes->sum('total_commande'),
            'encaisse' => (float) $factures->sum(fn (FactureVente $f) => $f->montant_encaisse),
            'reste_du' => (float) $factures->sum(fn (FactureVente $f) => $f->montant_restant),
            'nb_ventes' => $ventes->count(),
        ];
    }

    /**
     * Agrégé par variante (grain transactionnel réel), trié par quantité vendue décroissante.
     *
     * @param  Collection<int, CommandeVente>  $ventes
     */
    private function produitsVendus(Collection $ventes): array
    {
        return $ventes->flatMap(fn (CommandeVente $v) => $v->lignes)
            ->groupBy('variante_id')
            ->map(function (Collection $lignes) {
                // $ventes est trié du plus récent au plus ancien : le libellé affiché est celui de
                // la vente la plus récente (snapshot figé à la vente, jamais le nom courant), avec
                // le même repli que CommandeVenteFormBuilder pour les lignes antérieures aux snapshots.
                $derniere = $lignes->first();

                return [
                    'variante_id' => $derniere->variante_id,
                    'libelle' => $derniere->libelle_snapshot ?? $derniere->variante?->produit?->nom,
                    'quantite' => $lignes->sum(fn ($l) => (int) ($l->quantite_livree ?? $l->quantite_demandee)),
                    'montant' => (float) $lignes->sum('total_ligne'),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['quantite'], $b['montant']] <=> [$a['quantite'], $a['montant']])
            ->values()
            ->all();
    }

    /**
     * Chaque catégorie est valorisée au montant facturé (montant_net) de ses ventes : le total
     * des catégories redonne le facturé, sans double compte. La part non encaissée d'une vente
     * partielle reste dans « Partiel » (exposée à part dans reste_a_encaisser) — le « Reste dû »
     * global = reste_a_encaisser de « Dû » + celui de « Partiel ».
     *
     * Pourcentage du montant et pourcentage du nombre de ventes sont deux champs distincts.
     *
     * @param  Collection<int, FactureVente>  $factures
     */
    private function paiements(Collection $factures): array
    {
        $totalMontant = (float) $factures->sum(fn (FactureVente $f) => (float) $f->montant_net);
        $totalVentes = $factures->count();

        $repartition = [];
        foreach (self::CATEGORIES_PAIEMENT as $code => $categorie) {
            $groupe = $factures->filter(fn (FactureVente $f) => in_array($f->statut_facture, $categorie['statuts'], true));
            $montant = (float) $groupe->sum(fn (FactureVente $f) => (float) $f->montant_net);
            $nbVentes = $groupe->count();

            $repartition[] = [
                'code' => $code,
                'label' => $categorie['label'],
                'montant' => $montant,
                'pourcentage_montant' => $this->pourcentage($montant, $totalMontant),
                'nb_ventes' => $nbVentes,
                'pourcentage_ventes' => $this->pourcentage($nbVentes, $totalVentes),
                'reste_a_encaisser' => (float) $groupe->sum(fn (FactureVente $f) => $f->montant_restant),
            ];
        }

        return [
            'total_montant' => $totalMontant,
            'total_ventes' => $totalVentes,
            'repartition' => $repartition,
        ];
    }

    private function pourcentage(float|int $part, float|int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }
}
