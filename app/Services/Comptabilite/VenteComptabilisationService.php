<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\ModePaiement;
use App\Models\CommandeVenteRetour;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use App\Services\Tresorerie\CaisseAgentResolver;
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
 *  - VENTE_RETOUR : un retour de livraison avant encaissement diminue une facture déjà
 *    comptabilisée — écriture inverse sur la valeur retournée : débit Ventes, crédit Client (cf.
 *    comptabiliserRetourVente()).
 *  - ENCAISSEMENT_VENTE_RECU : chaque EncaissementVente créé (partiel ou
 *    total) — règlement de la créance : débit Trésorerie, crédit Client. La
 *    trésorerie débitée est le compte du moyen de paiement (compta_mappings), ou le
 *    sous-compte de la caisse dédiée de l'agent pour ses encaissements en espèces
 *    (cf. CaisseAgentResolver).
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
        private readonly CaisseAgentResolver $caisses,
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

    /**
     * Régularise la créance client après un retour de livraison avant encaissement (cf.
     * CommandeVenteRetourService) : la facture avait déjà été comptabilisée pour la marchandise
     * chargée (VENTE_FACTUREE), le retour en diminue le montant — écriture inverse sur la seule
     * valeur retournée (débit Ventes, crédit Client). Une pièce PAR retour, jamais une
     * contrepassation de la pièce d'origine : des retours partiels successifs se cumuleraient
     * sinon en double. Sans effet si la facture n'a jamais été comptabilisée (montant nul, échec de
     * comptabilisation en amont) — il n'y a alors rien à régulariser.
     */
    public function comptabiliserRetourVente(CommandeVenteRetour $retour): ?PieceComptable
    {
        if (! $this->retourARegulariser($retour)) {
            return null;
        }

        $montant = round((float) $retour->montant_retourne, 2);
        $commande = $retour->commande;
        $facture = $commande->facture;

        $ligneClient = [
            'role' => 'client',
            'sens' => 'credit',
            'montant' => $montant,
        ];
        if ($commande->client) {
            $ligneClient['tiers_type'] = 'client';
            $ligneClient['tiers_model'] = $commande->client;
        }

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::VENTE_RETOUR,
            source: $retour,
            organizationId: $facture->organization_id,
            dateComptable: Carbon::parse($retour->created_at ?? now()),
            libelle: 'Retour de livraison — facture '.$facture->reference,
            lignes: [
                ['role' => 'produit_vente', 'sens' => 'debit', 'montant' => $montant],
                $ligneClient,
            ],
            siteId: $facture->site_id,
            createdBy: $retour->created_by,
        );
    }

    /**
     * Un retour n'appelle une écriture de régularisation que si la pièce VENTE_FACTUREE de sa
     * facture existait DÉJÀ quand il a été enregistré : elle porte alors le montant d'avant retour.
     * Une pièce postée après (rattrapage d'une comptabilisation en échec, cf. ComptabiliteRattrapage
     * Command) est déjà calculée sur le montant net de la facture — y ajouter la régularisation
     * compterait le retour deux fois. Aussi faux sans facture, sans pièce de vente (montant nul,
     * échec en amont — le rattrapage de la vente la comptabilisera alors au net) ou à montant nul.
     * Source unique partagée par la comptabilisation, le rattrapage et l'audit.
     */
    public function retourARegulariser(CommandeVenteRetour $retour): bool
    {
        if (round((float) $retour->montant_retourne, 2) <= 0) {
            return false;
        }

        $retour->loadMissing('commande.facture', 'commande.client');
        $facture = $retour->commande?->facture;
        if (! $facture) {
            return false;
        }

        $pieceVente = $this->ecritures->pieceExistantePour($facture->organization_id, $facture, EvenementComptable::VENTE_FACTUREE);

        return $pieceVente !== null && $pieceVente->created_at->lte($retour->created_at);
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

        // Espèces encaissées par un agent qui a une caisse dédiée sur ce site : la ligne de
        // trésorerie vise directement le sous-compte de SA caisse (le moteur accepte un compte
        // déjà résolu, comme pour la charge d'une dépense) au lieu du compte 571000 partagé —
        // cf. CaisseAgentResolver pour les conditions exactes. Le crédit reste sur le compte
        // client : le produit est déjà constaté à la facturation (VENTE_FACTUREE).
        $caisse = $this->caisses->pourEncaissement($encaissement, $facture);
        $ligneTresorerie = $caisse === null
            ? [
                'role' => 'tresorerie',
                'sens' => 'debit',
                'montant' => $montant,
                'moyen_paiement' => $encaissement->mode_paiement?->value,
            ]
            : [
                'compte_comptable_id' => $caisse->compte_comptable_id,
                // Le journal reste celui d'un encaissement en espèces (« Caisse ») : la ligne
                // client n'en porte pas, cf. EcritureComptableService (option journal_role).
                'journal_role' => 'tresorerie',
                'moyen_paiement' => ModePaiement::ESPECES->value,
                'sens' => 'debit',
                'montant' => $montant,
                'libelle' => 'Encaissement facture '.$facture->reference.' — '.$caisse->libelle,
            ];

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
                $ligneTresorerie,
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
