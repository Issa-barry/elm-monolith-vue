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
    case DEPENSE_INTERNE_VALIDEE = 'depense_interne_validee';
    case DEPENSE_AVANCE_TIERS_VALIDEE = 'depense_avance_tiers_validee';
    case REGULARISATION_CLOTURE_FICHE = 'regularisation_cloture_fiche';
    // Vente/facturation client — cf. VenteComptabilisationService. Fait générateur =
    // sortie de FactureVente du statut CREEE (montant définitif, quantités réellement
    // chargées), jamais la création de la facture (encore une estimation) ni la
    // livraison physique (statut LIVREE, purement logistique).
    case VENTE_FACTUREE = 'vente_facturee';
    // Fait générateur = EncaissementVente créé (chaque encaissement, partiel ou total).
    case ENCAISSEMENT_VENTE_RECU = 'encaissement_vente_recu';
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

    public function label(): string
    {
        return match ($this) {
            self::FICHE_PROPRIETAIRE_VALIDEE => 'Fiche propriétaire validée',
            self::FICHE_LIVREUR_VALIDEE => 'Fiche livreur validée',
            self::PAIEMENT_PROPRIETAIRE => 'Paiement propriétaire',
            self::PAIEMENT_LIVREUR => 'Paiement livreur',
            self::DEPENSE_INTERNE_VALIDEE => 'Dépense interne validée',
            self::DEPENSE_AVANCE_TIERS_VALIDEE => 'Dépense imputée à un tiers (avance)',
            self::REGULARISATION_CLOTURE_FICHE => 'Régularisation de clôture (fiche non validée)',
            self::VENTE_FACTUREE => 'Vente facturée',
            self::ENCAISSEMENT_VENTE_RECU => 'Encaissement client reçu',
            self::PAIEMENT_SALAIRE => 'Paiement salaire',
            self::MOUVEMENT_FONDS_ENVOYE => 'Mouvement de fonds — envoi',
            self::MOUVEMENT_FONDS_RECU => 'Mouvement de fonds — réception',
            self::SOLDE_OUVERTURE_TRESORERIE => 'Solde d\'ouverture trésorerie',
        };
    }
}
