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
        };
    }
}
