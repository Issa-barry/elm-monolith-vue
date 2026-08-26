<?php

namespace App\Enums;

/**
 * Unité de calcul d'une commission_regle.
 *
 * MARGE_OPERATION : pont de re-plateforme Phase 1 uniquement — reproduit exactement
 * l'ancienne formule CommissionCalculator (max(0, prix_vente - prix_usine) sur
 * l'opération, réparti par pourcentage). `commission_regles.montant` est ignoré
 * pour cette unité (aucun taux unitaire, la base est la marge réelle de chaque
 * opération). Vouée à disparaître au profit de PAR_UNITE_VENDUE quand le grain
 * catégorie (Phase 2) introduit de vrais barèmes fixes métier.
 *
 * PAR_UNITE_VENDUE : montant fixe × quantité de la ligne — la cible V1 du nouveau
 * modèle (barème par catégorie/produit/variante), pas encore utilisée tant que
 * la Phase 2 n'a pas ouvert le grain catégorie.
 *
 * PAR_FORFAIT / PAR_POURCENTAGE : volontairement ABSENTES de cet enum. Leur
 * formule métier n'est pas spécifiée — ne pas les ajouter avant une décision
 * métier explicite (cf. conception cible §0.1.1).
 */
enum CommissionUniteCalcul: string
{
    case MARGE_OPERATION = 'marge_operation';
    case PAR_UNITE_VENDUE = 'par_unite_vendue';

    public function label(): string
    {
        return match ($this) {
            self::MARGE_OPERATION => 'Marge de l\'opération (legacy Phase 1)',
            self::PAR_UNITE_VENDUE => 'Par unité vendue',
        };
    }
}
