<?php

namespace App\Features;

/**
 * Activation des tableaux de bord « Stat- » par organisation (ADR 0071,
 * docs/tableaux-de-bord-activation.md).
 *
 * Même mécanisme que les modules métier (Pennant, portée Organization, table `features`) mais
 * notion distincte : un tableau de bord désactivé disparaît du menu et sa route est refusée,
 * le module dont il lit les données reste actif. Les deux contrôles s'ajoutent à la
 * permission vérifiée par le contrôleur, ils ne la remplacent jamais.
 *
 * La valeur par défaut n'est résolue et enregistrée qu'à la première lecture pour une
 * organisation : une valeur déjà enregistrée (choix de l'administrateur compris) n'est jamais
 * recalculée, ni par un déploiement ni par un seeder.
 */
class TableauDeBordFeature
{
    public const VENTES = 'tableau_de_bord.ventes';

    public const COMMISSIONS = 'tableau_de_bord.commissions';

    public const DEPENSES = 'tableau_de_bord.depenses';

    public const STOCK = 'tableau_de_bord.stock';

    public const RH = 'tableau_de_bord.rh';

    public const VEHICULES = 'tableau_de_bord.vehicules';

    /** Ordre du menu « Tableau de bord » et de la page Paramètres → Tableaux de bord. */
    public const ALL = [
        self::VENTES,
        self::COMMISSIONS,
        self::DEPENSES,
        self::STOCK,
        self::RH,
        self::VEHICULES,
    ];

    /** Activés tant qu'aucun choix n'est enregistré pour l'organisation ; les autres sont désactivés. */
    public const ACTIFS_PAR_DEFAUT = [
        self::VENTES,
        self::STOCK,
    ];

    private const PREFIXE = 'tableau_de_bord.';

    public static function defaultState(string $tableau): bool
    {
        return in_array($tableau, self::ACTIFS_PAR_DEFAUT, true);
    }

    /** Clé courte partagée avec le front (`tableaux_de_bord.ventes`…), sans le préfixe pointé. */
    public static function cleCourte(string $tableau): string
    {
        return substr($tableau, strlen(self::PREFIXE));
    }

    /** @return array<string, string> */
    public static function libelles(): array
    {
        return [
            self::VENTES => 'Stat-Ventes',
            self::COMMISSIONS => 'Stat-Commissions',
            self::DEPENSES => 'Stat-Dépenses',
            self::STOCK => 'Stat-Stock',
            self::RH => 'Stat-RH',
            self::VEHICULES => 'Stat-Véhicules',
        ];
    }

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::VENTES => 'Chiffre d\'affaires, encaissements et répartitions des ventes, affichés sur la page d\'accueil.',
            self::COMMISSIONS => 'Vue consolidée des commissions déjà générées, par période et par agence.',
            self::DEPENSES => 'Vue consolidée des dépenses, par période et par agence.',
            self::STOCK => 'État du stock et mouvements, par produit et par agence.',
            self::RH => 'Salariés, contrats et absences.',
            self::VEHICULES => 'Disponibilité du parc, rotations et dépenses des véhicules.',
        ];
    }
}
