<?php

namespace App\Services\Vehicules;

use App\Enums\StatutCommandeVente;
use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\ModuleService;
use App\Services\SiteScopeService;
use App\Support\Periodes\Periode;
use App\Support\Situation\SituationVentesAgregats;
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
 *
 * Droits (27/11/2026) : `vehicules.read` ouvre la fiche, jamais les chiffres de vente. La section
 * exige `ventes.read` et le module Ventes actif, et ne retient que les ventes des agences que
 * l'utilisateur consulte sur l'écran Ventes (`commandes_ventes.site_id`).
 */
class VehiculeSituationVentesService
{
    public function __construct(private readonly SiteScopeService $siteScope) {}

    /**
     * Section calculée pour ce consulteur, ou null s'il n'a pas le droit de lire les ventes : rien
     * n'est alors calculé ni envoyé au navigateur, et l'onglet est masqué.
     *
     * @return array{kpis: array, produits: array, paiements: array}|null
     */
    public function pourConsulteur(User $user, Vehicule $vehicule, Periode $periode): ?array
    {
        if (! $user->can('ventes.read') || ! ModuleService::isActive(ModuleFeature::VENTES, $user->organization)) {
            return null;
        }

        return $this->pourVehicule($vehicule, $periode, $this->agencesConsultables($user));
    }

    /**
     * Agences dont l'utilisateur lit les ventes : null = toutes (vision de toutes les agences, qui
     * compte aussi les ventes sans agence) ; sinon ses agences de rattachement, une liste vide ne
     * donnant accès à aucune vente.
     *
     * @return list<string>|null
     */
    private function agencesConsultables(User $user): ?array
    {
        if ($user->voitToutesLesAgences()) {
            return null;
        }

        return $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * Les ventes sont retenues sur leur date de création, dans les bornes de la période commune
     * de la Situation (Periode) ; sans bornes = toute la période. $siteIds : agences retenues
     * (`commandes_ventes.site_id`), null = aucune restriction.
     *
     * @param  list<string>|null  $siteIds
     * @return array{kpis: array, produits: array, paiements: array}
     */
    public function pourVehicule(Vehicule $vehicule, Periode $periode, ?array $siteIds): array
    {
        $query = CommandeVente::where('vehicule_id', $vehicule->id)
            ->where('organization_id', $vehicule->organization_id)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds))
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
