<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;

/**
 * Traduit le cycle de vente (facture, encaissement) en écritures comptables —
 * périmètre absent de la V1 du module (qui ne couvrait que dépenses et fiches
 * de commission), cf. docs/data-dictionary-compta.md.
 *
 * Deux faits générateurs distincts, jamais confondus avec le cycle logistique
 * (le statut CommandeVente::LIVREE est un simple suivi de livraison physique,
 * pas un jalon comptable — cf. CommandeVenteService) :
 *
 *  - VENTE_FACTUREE : la facture quitte le statut CREEE (montant encore une
 *    estimation) pour IMPAYEE/PARTIEL/PAYEE (montant définitif, sur quantités
 *    réellement chargées) — cf. CommandeVenteService::activerFacture()
 *    et ::creerFactureDirecte(). Créance client constatée : débit Client,
 *    crédit Ventes.
 *  - ENCAISSEMENT_VENTE_RECU : chaque EncaissementVente créé (partiel ou
 *    total) — règlement de la créance : débit Trésorerie, crédit Client.
 *
 * Ne comptabilise jamais la commission d'un livreur/propriétaire elle-même :
 * cette traduction reste entièrement portée par FicheComptabilisationService,
 * au moment de la validation de la fiche — une CommissionVente/CommissionLogistique
 * n'est qu'un calcul intermédiaire, jamais un fait générateur comptable direct
 * (évite tout double comptage entre le CA de la vente et la charge de commission
 * versée au livreur, qui sont deux écritures économiquement distinctes).
 */
class VenteComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    /**
     * Vente comptant sans client identifié (PDV anonyme, CommandeVente.client_id
     * null) : comptabilisée quand même sur le compte collectif 411, mais sans
     * ligne compta_tiers nominative — pas de créance à suivre individuellement
     * pour une vente comptant.
     */
    public function comptabiliserVenteFacturee(FactureVente $facture): ?PieceComptable
    {
        $montant = round((float) $facture->montant_net, 2);
        // Facture soldée d'office à 0 (ex: commande entièrement annulée au chargement,
        // cf. CommandeVenteService::activerFacture()) : rien à comptabiliser, comme
        // pour les fiches/commissions à montant nul.
        if ($montant <= 0) {
            return null;
        }

        $facture->loadMissing('commande.client');
        $client = $facture->commande?->client;

        $ligneClient = [
            'role' => 'client',
            'sens' => 'debit',
            'montant' => $montant,
        ];
        if ($client) {
            $ligneClient['tiers_type'] = 'client';
            $ligneClient['tiers_model'] = $client;
        }

        // Date du fait générateur réel : validation du chargement (montant devenu
        // définitif), pas l'instant de l'appel — condition nécessaire pour que le
        // rattrapage historique (Phase 4) puisse rejouer cet événement à sa vraie
        // date métier plutôt qu'à la date du rattrapage. Repli sur la création de la
        // facture pour le chemin direct (creerFactureDirecte(), pas de chargement).
        $dateComptable = Carbon::parse($facture->commande?->chargement_valide_at ?? $facture->created_at ?? now());

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::VENTE_FACTUREE,
            source: $facture,
            organizationId: $facture->organization_id,
            dateComptable: $dateComptable,
            libelle: 'Facture '.$facture->reference,
            lignes: [
                $ligneClient,
                ['role' => 'produit_vente', 'sens' => 'credit', 'montant' => $montant],
            ],
            siteId: $facture->site_id,
        );
    }

    public function comptabiliserEncaissementVente(EncaissementVente $encaissement): ?PieceComptable
    {
        $montant = round((float) $encaissement->montant, 2);
        if ($montant <= 0) {
            return null;
        }

        $encaissement->loadMissing('facture.commande.client');
        $facture = $encaissement->facture;
        if (! $facture) {
            return null;
        }
        $client = $facture->commande?->client;

        $ligneClient = [
            'role' => 'client',
            'sens' => 'credit',
            'montant' => $montant,
        ];
        if ($client) {
            $ligneClient['tiers_type'] = 'client';
            $ligneClient['tiers_model'] = $client;
        }

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::ENCAISSEMENT_VENTE_RECU,
            source: $encaissement,
            // encaissements_ventes ne porte pas organization_id (pas de FK dédiée dans
            // ce module, historique antérieur au multi-tenant strict) — toujours dérivé
            // de la facture parente.
            organizationId: $facture->organization_id,
            dateComptable: Carbon::parse($encaissement->date_encaissement ?? now()),
            libelle: 'Encaissement facture '.$facture->reference,
            lignes: [
                [
                    'role' => 'tresorerie',
                    'sens' => 'debit',
                    'montant' => $montant,
                    'moyen_paiement' => $encaissement->mode_paiement?->value,
                ],
                $ligneClient,
            ],
            siteId: $facture->site_id,
            createdBy: $encaissement->created_by,
        );
    }

    /**
     * Contrepasse la pièce VENTE_FACTUREE d'une facture annulée après avoir été
     * comptabilisée — appelée pour toute commande annulée avec facture (flotte
     * comme vente directe, cf. CommandeVenteService::annuler()), mais reste un
     * no-op dans la pratique pour le chemin flotte : une commande flotte n'est
     * annulable que depuis A_CHARGER (cf. StatutCommandeVente::isAnnulable()),
     * stade auquel sa facture est encore CREEE et n'a jamais été comptabilisée
     * (comptabiliserVenteFacturee() n'a lieu qu'au chargement validé). Aucun
     * effet si la facture n'avait jamais été comptabilisée.
     */
    public function contrepasserVenteFactureeSiExistante(FactureVente $facture, string $motif): ?PieceComptable
    {
        $piece = $this->ecritures->pieceExistantePour($facture->organization_id, $facture, EvenementComptable::VENTE_FACTUREE);

        if (! $piece || ! $piece->isValidee()) {
            return null;
        }

        return $this->ecritures->contrepasser($piece, $motif);
    }
}
