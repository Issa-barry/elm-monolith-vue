<?php

namespace App\Services\Vehicules;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Vehicule;
use App\Support\Situation\SituationVentesAgregats;
use App\Support\Vehicules\SituationPeriode;
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
 * Produits vendus et situation des paiements : SituationVentesAgregats, partagé avec la fiche agent.
 */
class VehiculeSituationVentesService
{
    /**
     * Les ventes sont retenues sur leur date de création, dans les bornes de la période commune
     * de la Situation (SituationPeriode) ; sans bornes = toute la période.
     *
     * @return array{kpis: array, produits: array, paiements: array}
     */
    public function pourVehicule(Vehicule $vehicule, SituationPeriode $periode): array
    {
        $query = CommandeVente::where('vehicule_id', $vehicule->id)
            ->whereNotIn('statut', [StatutCommandeVente::BROUILLON->value, StatutCommandeVente::ANNULEE->value, StatutCommandeVente::RETOURNEE->value, StatutCommandeVente::ANNULEE_ERREUR_SAISIE->value, StatutCommandeVente::RESERVEE->value, StatutCommandeVente::A_PREPARER->value, StatutCommandeVente::PREPAREE->value])
            ->with(['lignes.variante.produit', 'facture.encaissements'])
            ->orderByDesc('created_at');

        if ($periode->debut && $periode->fin) {
            $query->whereBetween('created_at', [$periode->debut, $periode->fin]);
        }

        $ventes = $query->get();
        $factures = $this->facturesActives($ventes);

        return [
            'kpis' => $this->kpis($ventes, $factures),
            'produits' => $this->produitsVendus($ventes),
            'paiements' => $this->paiements($factures),
        ];
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
     * @param  Collection<int, CommandeVente>  $ventes  de la plus récente à la plus ancienne
     */
    private function produitsVendus(Collection $ventes): array
    {
        return SituationVentesAgregats::produitsVendus($ventes->flatMap(fn (CommandeVente $v) => $v->lignes));
    }

    /**
     * @param  Collection<int, FactureVente>  $factures
     */
    private function paiements(Collection $factures): array
    {
        return SituationVentesAgregats::paiements($factures);
    }
}
