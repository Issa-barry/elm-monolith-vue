import type {
    PrimeVuePrimaryName,
    PrimeVueSurfaceName,
    PrimeVueThemeName,
} from '@/lib/primevue-theme';

/**
 * Forme du prop Inertia partagé `theme` (HandleInertiaRequests::theme()).
 * Le serveur (ThemePolicyService) est l'autorité : le frontend n'affiche que
 * ce qui est `allowed`, jamais une liste devinée localement — cf. docs/theming.md.
 */
export interface ThemeActive {
    preset: PrimeVueThemeName;
    primary: PrimeVuePrimaryName;
    surface: PrimeVueSurfaceName;
}

export interface ThemeAllowed {
    presets: PrimeVueThemeName[];
    primaries: PrimeVuePrimaryName[];
    surfaces: PrimeVueSurfaceName[];
}

export interface ThemeLocked {
    preset: boolean;
    primary: boolean;
    surface: boolean;
}

export interface ThemeSharedProps {
    active: ThemeActive;
    allowed: ThemeAllowed;
    locked: ThemeLocked;
}
