<?php

namespace App\Enums;

/**
 * Catalogue fermé des événements métier que le moteur comptable sait traiter.
 *
 * Ce sont des noms de contrat entre le métier et la comptabilité — pas des
 * numéros de compte. Chaque événement a besoin d'un jeu de "rôles" (voir
 * EcritureComptableService::ROLES_PAR_EVENEMENT) que l'organisation doit
 * mapper vers de vrais comptes via compta_mappings avant de pouvoir être
 * comptabilisé (sinon MappingComptableIndisponibleException).
 */
enum EvenementComptable: string
{
    case FICHE_PROPRIETAIRE_VALIDEE = 'fiche_proprietaire_validee';
    case FICHE_LIVREUR_VALIDEE = 'fiche_livreur_validee';
    case PAIEMENT_PROPRIETAIRE = 'paiement_proprietaire';
    case PAIEMENT_LIVREUR = 'paiement_livreur';
    // Commission site et commission consultant (App\Models\PaiementFiche,
    // beneficiaire_type='site'|'prestataire') — même traitement engagement +
    // règlement que propriétaire/livreur (cf. FicheComptabilisationService),
    // resté non branché jusqu'au chantier du 2026-08-22 (TYPES_SUPPORTES ne
    // couvrait que proprietaire/livreur alors que PeriodeCalculatorService
    // génère bien des PaiementFiche pour ces deux types).
    case FICHE_SITE_VALIDEE = 'fiche_site_validee';
    case FICHE_CONSULTANT_VALIDEE = 'fiche_consultant_validee';
    case PAIEMENT_SITE = 'paiement_site';
    case PAIEMENT_CONSULTANT = 'paiement_consultant';
    case DEPENSE_INTERNE_VALIDEE = 'depense_interne_validee';
    case DEPENSE_AVANCE_TIERS_VALIDEE = 'depense_avance_tiers_validee';
    case REGULARISATION_CLOTURE_FICHE = 'regularisation_cloture_fiche';
    // Vente/facturation client — cf. VenteComptabilisationService. Fait générateur =
    // sortie de FactureVente du statut CREEE (montant définitif, quantités réellement
    // chargées), jamais la création de la facture (encore une estimation) ni la
    // livraison physique (statut LIVREE, purement logistique).
    case VENTE_FACTUREE = 'vente_facturee';
    // Régularisation d'une facture déjà comptabilisée après un retour de livraison avant
    // encaissement (App\Models\CommandeVenteRetour) — écriture inverse de VENTE_FACTUREE sur la
    // seule valeur retournée (débit Ventes, crédit Client), une pièce par retour, jamais une
    // contrepassation de la pièce d'origine (des retours partiels successifs se cumuleraient).
    case VENTE_RETOUR = 'vente_retour';
    // Fait générateur = EncaissementVente créé (chaque encaissement, partiel ou total).
    case ENCAISSEMENT_VENTE_RECU = 'encaissement_vente_recu';
    // Jambe « agence de la commande » d'un encaissement réalisé par une AUTRE agence (ADR 0012) :
    // débit liaison (tiers = agence qui a encaissé) / crédit client, sur le site de la commande. La
    // pièce ENCAISSEMENT_VENTE_RECU, elle, est alors posée sur le site d'encaissement : débit
    // trésorerie / crédit liaison (tiers = agence de la commande).
    case ENCAISSEMENT_VENTE_POUR_COMPTE = 'encaissement_vente_pour_compte';
    // Paiement de salaire (PaiePaiement) — jambe trésorerie uniquement (pas
    // d'engagement/dette préalable comptabilisé, cf. PaieComptabilisationService) :
    // couvre juste la sortie de caisse/banque nécessaire au calcul du disponible
    // par agence (App\Services\Tresorerie\TresorerieDisponibiliteService).
    case PAIEMENT_SALAIRE = 'paiement_salaire';
    // Mouvement de fonds interne (App\Models\MouvementFonds) — un événement par
    // jambe (émission au site d'origine, réception au site de destination),
    // jamais les deux dans la même pièce (cf. MouvementFondsComptabilisationService).
    case MOUVEMENT_FONDS_ENVOYE = 'mouvement_fonds_envoye';
    case MOUVEMENT_FONDS_RECU = 'mouvement_fonds_recu';
    // Solde d'ouverture d'un support de trésorerie (App\Models\SoldeOuvertureTresorerie).
    case SOLDE_OUVERTURE_TRESORERIE = 'solde_ouverture_tresorerie';
    // Paiement direct d'une commission logistique (App\Models\CommissionPayment) — circuit
    // parallèle à PaiementFiche (PeriodePayabilityChecker::assertPartsNotClaimedByFiche
    // empêche qu'une même part soit payée deux fois par les deux circuits). Jambe trésorerie
    // uniquement, comme PAIEMENT_SALAIRE : aucun engagement préalable comptabilisé pour les
    // CommissionLogistiquePart aujourd'hui (audit du 2026-08-22).
    case PAIEMENT_COMMISSION_LOGISTIQUE_DIRECT = 'paiement_commission_logistique_direct';
    // Versement de cashback à un client (App\Models\CashbackVersement) — jambe
    // trésorerie uniquement, même convention que PAIEMENT_SALAIRE : c'était
    // auparavant la seule trace financière de ce flux (JournalTresorerie),
    // aucune écriture dans compta_ecritures (audit du 2026-08-22).
    case VERSEMENT_CASHBACK = 'versement_cashback';
    // Précommande remise (ADR 0019) : les acomptes reçus en avance client (419100) sont imputés sur
    // le compte client (411000) au moment où la vente est réalisée — aucune trésorerie ne bouge.
    case ACOMPTE_PRECOMMANDE_IMPUTE = 'acompte_precommande_impute';
    // Remboursement d'un client (RemboursementVente, ADR 0019) : sortie de trésorerie réelle, débit
    // avance client (précommande non remise) ou compte client (trop-perçu après remise).
    case REMBOURSEMENT_CLIENT = 'remboursement_client';
    // Fiche livreur/propriétaire déjà comptabilisée qui change d'agence (véhicule réaffecté, ADR
    // 0020) : une pièce par site — à l'origine, débit dette / crédit charge ; à la destination,
    // débit charge / crédit dette — sur le reste dû. Jamais par la liaison 181 : la dette entre
    // agences est dérivée des encaissements (ADR 0012), un solde de liaison ne serait jamais réglé.
    case FICHE_REAFFECTEE_SORTIE = 'fiche_reaffectee_sortie';
    case FICHE_REAFFECTEE_ENTREE = 'fiche_reaffectee_entree';

    public function label(): string
    {
        return match ($this) {
            self::FICHE_PROPRIETAIRE_VALIDEE => 'Fiche propriétaire validée',
            self::FICHE_LIVREUR_VALIDEE => 'Fiche livreur validée',
            self::PAIEMENT_PROPRIETAIRE => 'Paiement propriétaire',
            self::PAIEMENT_LIVREUR => 'Paiement livreur',
            self::FICHE_SITE_VALIDEE => 'Fiche commission site validée',
            self::FICHE_CONSULTANT_VALIDEE => 'Fiche commission consultant validée',
            self::PAIEMENT_SITE => 'Paiement commission site',
            self::PAIEMENT_CONSULTANT => 'Paiement commission consultant',
            self::DEPENSE_INTERNE_VALIDEE => 'Dépense interne validée',
            self::DEPENSE_AVANCE_TIERS_VALIDEE => 'Dépense imputée à un tiers (avance)',
            self::REGULARISATION_CLOTURE_FICHE => 'Régularisation de clôture (fiche non validée)',
            self::VENTE_FACTUREE => 'Vente facturée',
            self::VENTE_RETOUR => 'Retour de livraison (régularisation de facture)',
            self::ENCAISSEMENT_VENTE_RECU => 'Encaissement client reçu',
            self::ENCAISSEMENT_VENTE_POUR_COMPTE => 'Encaissement reçu par une autre agence',
            self::PAIEMENT_SALAIRE => 'Paiement salaire',
            self::MOUVEMENT_FONDS_ENVOYE => 'Mouvement de fonds — envoi',
            self::MOUVEMENT_FONDS_RECU => 'Mouvement de fonds — réception',
            self::SOLDE_OUVERTURE_TRESORERIE => 'Solde d\'ouverture trésorerie',
            self::PAIEMENT_COMMISSION_LOGISTIQUE_DIRECT => 'Paiement direct commission logistique',
            self::VERSEMENT_CASHBACK => 'Versement cashback',
            self::ACOMPTE_PRECOMMANDE_IMPUTE => 'Imputation des acomptes de précommande',
            self::REMBOURSEMENT_CLIENT => 'Remboursement client',
            self::FICHE_REAFFECTEE_SORTIE => "Fiche réaffectée — sortie de l'agence d'origine",
            self::FICHE_REAFFECTEE_ENTREE => 'Fiche réaffectée — entrée dans la nouvelle agence',
        };
    }
}
