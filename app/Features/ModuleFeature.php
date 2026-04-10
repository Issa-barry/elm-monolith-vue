<?php

namespace App\Features;

class ModuleFeature
{
    // ── Constantes stables ────────────────────────────────────────────────────

    public const VENTES = 'module.ventes';

    public const ACHATS = 'module.achats';

    public const PACKINGS = 'module.packings';

    public const PRESTATAIRES = 'module.prestataires';

    public const VEHICULES = 'module.vehicules';

    public const PRODUITS = 'module.produits';

    public const SITES = 'module.sites';

    public const UTILISATEURS = 'module.utilisateurs';

    public const INSCRIPTION = 'module.inscription';

    public const CASHBACK = 'module.cashback';

    public const ALL = [
        self::VENTES,
        self::ACHATS,
        self::PACKINGS,
        self::PRESTATAIRES,
        self::VEHICULES,
        self::PRODUITS,
        self::SITES,
        self::UTILISATEURS,
        self::INSCRIPTION,
        self::CASHBACK,
    ];

    /**
     * Modules desactives par defaut (si aucune valeur n'est encore persistée).
     */
    public const DEFAULT_DISABLED = [
        self::ACHATS,
        self::PACKINGS,
        self::PRESTATAIRES,
        self::INSCRIPTION,
        self::CASHBACK,
    ];

    public static function defaultState(string $module): bool
    {
        return ! in_array($module, self::DEFAULT_DISABLED, true);
    }

    // ── Libellés UI ───────────────────────────────────────────────────────────

    public static function labels(): array
    {
        return [
            self::VENTES => 'Ventes',
            self::ACHATS => 'Achats',
            self::PACKINGS => 'Packings',
            self::PRESTATAIRES => 'Prestataires',
            self::VEHICULES => 'Véhicules',
            self::PRODUITS => 'Produits',
            self::SITES => 'Sites',
            self::UTILISATEURS => 'Utilisateurs',
            self::INSCRIPTION => 'Inscription',
            self::CASHBACK => 'Cashback clients',
        ];
    }
}
