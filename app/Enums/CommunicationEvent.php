<?php

namespace App\Enums;

/**
 * Événement métier déclencheur d'une `App\Models\CommunicationRule` — nommé
 * explicitement d'après la VRAIE transition d'état, jamais d'après le nom
 * d'une méthode de contrôleur (cf. rapport notifications de commande,
 * 07/09/2026, point 1) :
 *
 * - `COMMANDE_CONFIRMEE` : Ventes uniquement — `StatutCommandeVente::BROUILLON
 *   → A_CHARGER` (CommandeVenteController::valider(), même point que
 *   NotifierLivreursCommandeVenteJob existant). PAS la création brute
 *   (store()) — une commande en brouillon peut encore changer de véhicule/
 *   équipe avant confirmation.
 * - `TRANSFERT_CREE` : Logistique uniquement — création du transfert avec une
 *   équipe de livraison assignée (TransfertLogistiqueController::store(),
 *   même point que NotifierLivreursTransfertJob existant). Contrairement aux
 *   Ventes, un transfert n'a pas d'étape de confirmation distincte de sa
 *   création : ce nom n'est donc pas ambigu ici.
 * - `CHARGEMENT_VALIDE` : partagé Ventes (`CHARGEMENT_EN_COURS →
 *   LIVRAISON_EN_COURS`) et Logistique (`CHARGEMENT → TRANSIT`), toutes deux
 *   dans la méthode générique `avancer()` de leur contrôleur de statut
 *   respectif — désambiguïsé par `CommunicationRule.module`, jamais par le nom
 *   de méthode (qui est le même des deux côtés).
 */
enum CommunicationEvent: string
{
    case COMMANDE_CONFIRMEE = 'commande_confirmee';
    case TRANSFERT_CREE = 'transfert_cree';
    case CHARGEMENT_VALIDE = 'chargement_valide';

    public function label(): string
    {
        return match ($this) {
            self::COMMANDE_CONFIRMEE => 'Commande confirmée',
            self::TRANSFERT_CREE => 'Transfert créé',
            self::CHARGEMENT_VALIDE => 'Chargement validé',
        };
    }
}
