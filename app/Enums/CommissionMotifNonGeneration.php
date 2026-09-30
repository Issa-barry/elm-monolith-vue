<?php

namespace App\Enums;

/**
 * Motif pour lequel une cible de commission attendue n'a pas été générée — un code par erreur
 * de résolution connue de CommissionEnveloppeGenerator::genererDepuisContexte(). Stocké dans
 * `commission_generation_attempts.detail_erreur.cibles[].code` depuis le monitoring des
 * commissions (30/09/2026) ; les tentatives antérieures, qui ne portent que le texte, sont
 * reclassées par depuisMessage() à la lecture — jamais réécrites (table append-only).
 *
 * Jamais un motif pour « aucun barème » ou « barème à 0 » : aucune commission n'est alors due
 * (décision AMOA #4), ce n'est pas une anomalie.
 */
enum CommissionMotifNonGeneration: string
{
    case PARTAGE_LIVREUR_NON_CONFORME = 'partage_livreur_non_conforme';
    case PARTAGE_LIVREUR_MANQUANT = 'partage_livreur_manquant';
    case EQUIPE_LIVRAISON_MANQUANTE = 'equipe_livraison_manquante';
    case CATEGORIE_MANQUANTE = 'categorie_manquante';
    case PROPRIETAIRE_MANQUANT = 'proprietaire_manquant';
    case CONSULTANT_NON_DESIGNE = 'consultant_non_designe';
    case CONSULTANT_INACTIF = 'consultant_inactif';
    case SITE_MANQUANT = 'site_manquant';
    case PERIODE_FIGEE = 'periode_figee';
    case ERREUR_TECHNIQUE = 'erreur_technique';

    public function label(): string
    {
        return match ($this) {
            self::PARTAGE_LIVREUR_NON_CONFORME => 'Partage Livreur non conforme au barème',
            self::PARTAGE_LIVREUR_MANQUANT => 'Partage Livreur non configuré',
            self::EQUIPE_LIVRAISON_MANQUANTE => 'Véhicule sans équipe de livraison',
            self::CATEGORIE_MANQUANTE => 'Produit sans catégorie',
            self::PROPRIETAIRE_MANQUANT => 'Véhicule sans propriétaire',
            self::CONSULTANT_NON_DESIGNE => 'Aucun consultant désigné',
            self::CONSULTANT_INACTIF => 'Consultant inactif',
            self::SITE_MANQUANT => 'Opération sans site',
            self::PERIODE_FIGEE => 'Période de paiement déjà validée ou clôturée',
            self::ERREUR_TECHNIQUE => 'Erreur de génération',
        };
    }

    /** Ce qu'il faut corriger avant de relancer — affiché dans le détail de l'anomalie. */
    public function actionCorrective(): string
    {
        return match ($this) {
            self::PARTAGE_LIVREUR_NON_CONFORME => 'Corrigez le partage Livreur de l\'équipe du véhicule pour que la somme des parts égale exactement le barème, puis relancez.',
            self::PARTAGE_LIVREUR_MANQUANT => 'Configurez le partage Livreur de l\'équipe du véhicule pour cette catégorie, puis relancez.',
            self::EQUIPE_LIVRAISON_MANQUANTE => 'Affectez une équipe de livraison au véhicule, puis relancez.',
            self::CATEGORIE_MANQUANTE => 'Rattachez le produit vendu à une catégorie, puis relancez.',
            self::PROPRIETAIRE_MANQUANT => 'Renseignez le propriétaire du véhicule, puis relancez.',
            self::CONSULTANT_NON_DESIGNE => 'Désignez un consultant sur le barème Consultant de la catégorie (Paramètres > Commissions), puis relancez.',
            self::CONSULTANT_INACTIF => 'Réactivez le consultant ou désignez-en un autre sur le barème de la catégorie, puis relancez.',
            self::SITE_MANQUANT => 'Rattachez l\'opération à un site, puis relancez.',
            self::PERIODE_FIGEE => 'La part ne peut plus être ajoutée à une période validée ou clôturée : rouvrez la période si c\'est possible, sinon régularisez manuellement.',
            self::ERREUR_TECHNIQUE => 'Consultez le message d\'erreur ; si la cause est corrigée, relancez.',
        };
    }

    /**
     * Classement d'un message d'erreur texte (tentatives antérieures au monitoring, sans code) —
     * calé sur les messages exacts de CommissionEnveloppeGenerator et
     * CommissionPartageLivraisonValidator.
     */
    public static function depuisMessage(string $message): self
    {
        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'période de paiement') => self::PERIODE_FIGEE,
            str_contains($m, 'partage non configuré') => self::PARTAGE_LIVREUR_MANQUANT,
            str_contains($m, 'enveloppe livreur'),
            str_contains($m, 'dans le partage'),
            str_contains($m, 'montant fixe'),
            str_contains($m, 'doit être un entier'),
            str_contains($m, 'ne peut pas être négatif') => self::PARTAGE_LIVREUR_NON_CONFORME,
            str_contains($m, 'aucune équipe de livraison') => self::EQUIPE_LIVRAISON_MANQUANTE,
            str_contains($m, 'sans catégorie') => self::CATEGORIE_MANQUANTE,
            str_contains($m, 'sans propriétaire') => self::PROPRIETAIRE_MANQUANT,
            str_contains($m, 'aucun consultant désigné') => self::CONSULTANT_NON_DESIGNE,
            str_contains($m, 'consultant') && str_contains($m, 'plus actif') => self::CONSULTANT_INACTIF,
            str_contains($m, 'opération sans site') => self::SITE_MANQUANT,
            default => self::ERREUR_TECHNIQUE,
        };
    }
}
