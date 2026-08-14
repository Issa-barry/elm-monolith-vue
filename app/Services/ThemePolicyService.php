<?php

namespace App\Services;

use App\Models\Parametre;

/**
 * Autorité unique côté serveur pour la politique de thème global (preset
 * PrimeVue / couleur principale / couleur de surface). Le frontend ne fait
 * qu'afficher ce que ce service décide — cf. docs/theming.md.
 *
 * Politique = config/theming.php (figée au déploiement, pas administrable).
 * Valeur active = Parametre, scopé organisation (cf. Parametre::GROUPE_THEME).
 */
class ThemePolicyService
{
    public function allowedPresets(): array
    {
        return config('theming.allowed_presets');
    }

    public function allowedPrimaries(): array
    {
        return config('theming.allowed_primaries');
    }

    public function allowedSurfaces(): array
    {
        return config('theming.allowed_surfaces');
    }

    public function isPresetAllowed(string $value): bool
    {
        return in_array($value, $this->allowedPresets(), true);
    }

    public function isPrimaryAllowed(string $value): bool
    {
        return in_array($value, $this->allowedPrimaries(), true);
    }

    public function isSurfaceAllowed(string $value): bool
    {
        return in_array($value, $this->allowedSurfaces(), true);
    }

    /**
     * Un axe avec une seule valeur autorisée est verrouillé : pas de flag
     * "locked" séparé à synchroniser, c'est une conséquence directe de la
     * longueur de la liste autorisée (source unique de vérité).
     */
    public function isPresetLocked(): bool
    {
        return count($this->allowedPresets()) <= 1;
    }

    public function isPrimaryLocked(): bool
    {
        return count($this->allowedPrimaries()) <= 1;
    }

    public function isSurfaceLocked(): bool
    {
        return count($this->allowedSurfaces()) <= 1;
    }

    /**
     * Résout le thème réellement actif pour une organisation, en cascade :
     *   1. valeur choisie par un admin, persistée en base (Parametre) — SI
     *      elle est toujours autorisée par la politique courante ;
     *   2. sinon la valeur par défaut du déploiement (config/.env) — SI elle
     *      est autorisée ;
     *   3. sinon la première valeur autorisée (filet de sécurité ultime,
     *      jamais vide grâce à ThemeCatalog::parseList()).
     *
     * Le cas 1→2 couvre explicitement une ancienne valeur devenue interdite
     * après un changement de politique (cf. docs/theming.md, recette #9) :
     * on ne renvoie jamais telle quelle une valeur non autorisée.
     *
     * $orgId absent (visiteur non authentifié, ex: page de login) : on saute
     * directement à la valeur par défaut du déploiement.
     */
    public function resolveActiveTheme(?string $orgId): array
    {
        return [
            'preset' => $this->resolveAxis(
                $orgId ? Parametre::getThemePreset($orgId) : null,
                config('theming.default_preset'),
                $this->allowedPresets(),
                fn (string $v) => $this->isPresetAllowed($v),
            ),
            'primary' => $this->resolveAxis(
                $orgId ? Parametre::getThemePrimary($orgId) : null,
                config('theming.default_primary'),
                $this->allowedPrimaries(),
                fn (string $v) => $this->isPrimaryAllowed($v),
            ),
            'surface' => $this->resolveAxis(
                $orgId ? Parametre::getThemeSurface($orgId) : null,
                config('theming.default_surface'),
                $this->allowedSurfaces(),
                fn (string $v) => $this->isSurfaceAllowed($v),
            ),
        ];
    }

    private function resolveAxis(?string $stored, string $deploymentDefault, array $allowed, callable $isAllowed): string
    {
        if ($stored !== null && $isAllowed($stored)) {
            return $stored;
        }

        if ($isAllowed($deploymentDefault)) {
            return $deploymentDefault;
        }

        return $allowed[0] ?? $deploymentDefault;
    }

    /**
     * Payload complet (politique + valeur active) partagé au frontend via
     * Inertia — cf. HandleInertiaRequests::share().
     */
    public function sharedPayload(?string $orgId): array
    {
        return [
            'active' => $this->resolveActiveTheme($orgId),
            'allowed' => [
                'presets' => $this->allowedPresets(),
                'primaries' => $this->allowedPrimaries(),
                'surfaces' => $this->allowedSurfaces(),
            ],
            'locked' => [
                'preset' => $this->isPresetLocked(),
                'primary' => $this->isPrimaryLocked(),
                'surface' => $this->isSurfaceLocked(),
            ],
        ];
    }
}
