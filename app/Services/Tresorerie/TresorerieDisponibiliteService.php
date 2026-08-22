<?php

namespace App\Services\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\MouvementFonds;
use Carbon\Carbon;

/**
 * Position de trésorerie réelle d'un site, calculée depuis le grand livre
 * (compta_ecritures) — jamais depuis JournalTresorerie (registre parallèle
 * hors périmètre de ce chantier, cf. compte-rendu). Le compte de virements
 * internes (58) n'est rattaché à AUCUN CompteTresorerie : il est donc
 * naturellement exclu du "disponible" d'un site, sans logique dédiée — de
 * l'argent en transit n'appartient à aucun site tant qu'il n'est pas
 * réceptionné (cf. MouvementFondsComptabilisationService).
 */
class TresorerieDisponibiliteService
{
    /**
     * Solde cumulé (débit - crédit) de tous les supports de trésorerie actifs
     * d'un site, toutes pièces comptables datées au plus tard à $date — inclut
     * donc le solde d'ouverture (toujours daté avant toute activité) et tout
     * financement déjà réceptionné à cette date.
     */
    public function disponiblePourSite(string $organizationId, string $siteId, Carbon $date): float
    {
        $compteComptableIds = CompteTresorerie::forOrg($organizationId)
            ->where('site_id', $siteId)
            ->actifs()
            ->pluck('compte_comptable_id');

        if ($compteComptableIds->isEmpty()) {
            return 0.0;
        }

        $solde = EcritureComptable::query()
            ->whereIn('compte_comptable_id', $compteComptableIds)
            ->where('site_id', $siteId)
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $organizationId)->whereDate('date_piece', '<=', $date->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde');

        return round((float) $solde, 2);
    }

    /**
     * Fonds envoyés vers ce site mais pas encore confirmés reçus — jamais
     * comptés dans disponiblePourSite() (aucune pièce postée côté destination
     * tant que non reçu). Inclut CONTESTE : un litige non résolu ne rend les
     * fonds disponibles nulle part tant qu'il n'est pas tranché (RECU ou
     * RETOURNE).
     *
     * Si $echeanceDebut/$echeanceFin sont fournis, ne compte que les
     * mouvements visant CETTE échéance (evite qu'un même envoi couvre deux
     * besoins différents dans le calcul) plus ceux sans échéance déclarée
     * (remise/financement générique — traité prudemment comme pouvant
     * couvrir n'importe quel besoin, jamais ignoré : cf. revue Codex du
     * 2026-08-22 sur le risque de double financement).
     */
    public function fondsEnTransitVersSite(string $organizationId, string $siteId, ?Carbon $echeanceDebut = null, ?Carbon $echeanceFin = null): float
    {
        $query = MouvementFonds::where('organization_id', $organizationId)
            ->where('site_destination_id', $siteId)
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value]);

        if ($echeanceDebut && $echeanceFin) {
            $query->where(fn ($q) => $q
                ->whereNull('echeance_debut')
                ->orWhere(fn ($q2) => $q2
                    ->whereDate('echeance_debut', $echeanceDebut->toDateString())
                    ->whereDate('echeance_fin', $echeanceFin->toDateString())
                )
            );
        }

        return round((float) $query->sum('montant'), 2);
    }

    /**
     * Financements reçus par ce site, confirmés dans l'intervalle donné —
     * purement informatif (déjà inclus dans disponiblePourSite()) : sert à
     * expliquer au siège combien a déjà été envoyé sur CETTE échéance.
     */
    public function dejaFinancePourSite(string $organizationId, string $siteId, Carbon $debut, Carbon $fin): float
    {
        return round((float) MouvementFonds::where('organization_id', $organizationId)
            ->where('site_destination_id', $siteId)
            ->where('statut', StatutMouvementFonds::RECU->value)
            ->whereBetween('date_reception', [$debut->toDateString(), $fin->toDateString()])
            ->sum('montant'), 2);
    }
}
