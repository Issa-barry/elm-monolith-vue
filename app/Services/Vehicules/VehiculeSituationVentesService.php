<?php

namespace App\Services\Vehicules;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\Vehicule;
use Illuminate\Support\Collection;

/**
 * Situation commerciale d'un véhicule (onglet Vehicules/Show « Situation → Ventes »).
 * Ne réimplémente aucun calcul financier : réutilise FactureVente::montant_encaisse /
 * montant_restant (accesseurs existants, mêmes formules que IndexCommandeVenteController).
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
     * @return array{kpis: array, produits: array, ventes: array}
     */
    public function pourVehicule(Vehicule $vehicule, string $periode = 'all'): array
    {
        $query = CommandeVente::where('vehicule_id', $vehicule->id)
            ->whereNotIn('statut', [StatutCommandeVente::BROUILLON->value, StatutCommandeVente::ANNULEE->value])
            ->with(['lignes', 'client', 'facture.encaissements']);

        match ($periode) {
            'month' => $query->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month),
            'year' => $query->whereYear('created_at', now()->year),
            default => null,
        };

        $ventes = $query->orderByDesc('created_at')->get();

        return [
            'kpis' => $this->kpis($ventes),
            'produits' => $this->produitsVendus($ventes),
            'ventes' => $this->detailVentes($ventes),
        ];
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     */
    private function kpis(Collection $ventes): array
    {
        return [
            'ca_vendu' => (float) $ventes->sum('total_commande'),
            'encaisse' => (float) $ventes->sum(fn (CommandeVente $v) => $v->facture ? (float) $v->facture->montant_encaisse : 0.0),
            'reste_du' => (float) $ventes->sum(fn (CommandeVente $v) => $v->facture ? (float) $v->facture->montant_restant : 0.0),
            'nb_ventes' => $ventes->count(),
        ];
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     */
    private function produitsVendus(Collection $ventes): array
    {
        return $ventes->flatMap(fn (CommandeVente $v) => $v->lignes)
            ->groupBy('variante_id')
            ->map(function (Collection $lignes) {
                // Libellé snapshot figé à la vente — jamais le nom produit courant (le produit
                // peut avoir été renommé depuis, cf. CommandeVenteLigne.libelle_snapshot).
                $libelle = $lignes->first()->libelle_snapshot;

                return [
                    'variante_id' => $lignes->first()->variante_id,
                    'libelle' => $libelle,
                    'quantite' => $lignes->sum(fn ($l) => (int) ($l->quantite_livree ?? $l->quantite_demandee)),
                    'montant' => (float) $lignes->sum('total_ligne'),
                ];
            })
            ->sortByDesc('montant')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     */
    private function detailVentes(Collection $ventes): array
    {
        return $ventes->map(fn (CommandeVente $v) => [
            'id' => $v->id,
            'reference' => $v->reference,
            'date' => $v->created_at?->format('d/m/Y'),
            'client_nom' => $v->client?->nom_complet,
            'montant' => (float) $v->total_commande,
            'encaisse' => $v->facture ? (float) $v->facture->montant_encaisse : 0.0,
            'reste' => $v->facture ? (float) $v->facture->montant_restant : 0.0,
            'statut' => $v->statut?->value,
            'statut_label' => $v->statut_label,
        ])->values()->all();
    }
}
