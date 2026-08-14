<?php

namespace App\Support\Theming;

/**
 * Source unique des valeurs de thème valides côté serveur (miroir des unions
 * TypeScript PrimeVueThemeName/PrimeVuePrimaryName/PrimeVueSurfaceName dans
 * resources/js/lib/primevue-theme.ts — les palettes de couleurs restent
 * uniquement côté front, le backend n'a besoin que des clés pour valider).
 *
 * Ne mélange jamais "valeurs valides pour le moteur" (ce fichier) et
 * "valeurs autorisées pour un déploiement donné" (config/theming.php,
 * dérivé de ces mêmes listes).
 */
class ThemeCatalog
{
    public const PRESETS = ['aura', 'lara', 'material', 'nora', 'starter'];

    public const PRIMARIES = [
        'zinc', 'emerald', 'green', 'lime', 'yellow', 'sky', 'blue', 'indigo',
        'violet', 'purple', 'fuchsia', 'pink', 'rose', 'orange', 'amber', 'teal', 'cyan',
    ];

    public const SURFACES = ['zinc', 'slate', 'stone', 'neutral', 'gray'];

    /**
     * Couleurs visuellement "famille bleue" — à exclure explicitement des
     * politiques hors-production qui veulent garder le bleu comme repère
     * exclusif de la prod. Rien dans les données de couleur (hex bruts par
     * nuance) ne permet de déduire cette proximité perceptuelle automatiquement,
     * c'est pourquoi cette liste est curée à la main plutôt que calculée.
     */
    public const BLUE_FAMILY = ['blue', 'sky', 'cyan', 'indigo'];

    /** Filet de sécurité ultime si un déploiement n'a rien configuré du tout. */
    public const FALLBACK_PRESET = 'starter';

    public const FALLBACK_PRIMARY = 'blue';

    public const FALLBACK_SURFACE = 'slate';

    /**
     * Parse une liste CSV issue d'une variable d'environnement (ex:
     * "emerald,green,orange") en ne gardant que les valeurs reconnues par
     * $catalog. Si la variable est absente OU que rien de valide n'en ressort
     * (typo, valeur inconnue...), retombe sur $catalog entier plutôt que sur
     * une liste vide — un déploiement mal configuré ne doit jamais aboutir à
     * "aucune valeur autorisée" (writer bloqué sans recours).
     *
     * @param  array<int, string>  $catalog
     * @return array<int, string>
     */
    public static function parseList(?string $raw, array $catalog): array
    {
        if ($raw === null || trim($raw) === '') {
            return $catalog;
        }

        $values = collect(explode(',', $raw))
            ->map(fn (string $v) => strtolower(trim($v)))
            ->filter(fn (string $v) => $v !== '')
            ->filter(fn (string $v) => in_array($v, $catalog, true))
            ->unique()
            ->values()
            ->all();

        return $values !== [] ? $values : $catalog;
    }
}
