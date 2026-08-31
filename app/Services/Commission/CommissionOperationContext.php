<?php

namespace App\Services\Commission;

use App\Models\Site;
use App\Models\Vehicule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Contexte générique d'une opération pouvant générer des enveloppes de commission
 * (CommandeVente ou TransfertLogistique) — extrait le couplage à CommandeVente du cœur de
 * CommissionEnveloppeGenerator (genererDepuisContexte()), pour que la résolution de règles et la
 * répartition d'équipe restent strictement identiques quel que soit le processus/la source.
 *
 * Les champs `notif*` pilotent le texte de CommissionManquanteNotification/CommissionGenereeNotification
 * — leurs défauts reproduisent exactement le texte historique "vente" ; seul l'adaptateur transfert
 * logistique les surcharge.
 */
final class CommissionOperationContext
{
    /** @param Collection<int, Model> $lignes lignes source (CommandeVenteLigne|TransfertLigne), chacune avec une relation variante() */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $reference,
        public readonly float $montantReference,
        public readonly ?Vehicule $vehicule,
        public readonly ?Site $site,
        public readonly Carbon $earnedAt,
        public readonly string $sourceLigneType,
        public readonly string $quantiteField,
        public readonly Collection $lignes,
        public readonly string $notifSourceLabel = 'commande_vente',
        public readonly string $notifLibelleOperation = 'La facture de la commande',
        public readonly string $notifVerbeEvenement = 'encaissée',
        public readonly string $notifUrlPath = '/backoffice/ventes/',
        public readonly string $notifActionLabel = 'Voir la commande',
    ) {}
}
