import Aura from '@primeuix/themes/aura';
import Lara from '@primeuix/themes/lara';
import Material from '@primeuix/themes/material';
import Nora from '@primeuix/themes/nora';
import { usePreset } from '@primeuix/styled';

export type PrimeVueThemeName = 'aura' | 'lara' | 'material' | 'nora';

export const PRIMEVUE_THEME_STORAGE_KEY = 'primevue_theme';

const PRIMEVUE_PRESETS: Record<PrimeVueThemeName, object> = {
    aura: Aura,
    lara: Lara,
    material: Material,
    nora: Nora,
};

export function normalizePrimeVueTheme(
    value?: string | null,
): PrimeVueThemeName {
    const normalized = value?.toLowerCase();

    if (normalized === 'lara') return 'lara';
    if (normalized === 'material') return 'material';
    if (normalized === 'nora') return 'nora';

    return 'aura';
}

export function getPrimeVueThemePreset(
    value?: string | null,
): { name: PrimeVueThemeName; preset: object } {
    const name = normalizePrimeVueTheme(value);

    return {
        name,
        preset: PRIMEVUE_PRESETS[name],
    };
}

export function resolvePrimeVueThemeFromEnv(): PrimeVueThemeName {
    return normalizePrimeVueTheme(import.meta.env.VITE_PRIMEVUE_THEME || 'aura');
}

export function getStoredPrimeVueTheme(): PrimeVueThemeName | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const value = localStorage.getItem(PRIMEVUE_THEME_STORAGE_KEY);

    return value ? normalizePrimeVueTheme(value) : null;
}

export function setStoredPrimeVueTheme(value: PrimeVueThemeName) {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(PRIMEVUE_THEME_STORAGE_KEY, value);
}

export function applyPrimeVueThemePreset(value: PrimeVueThemeName) {
    usePreset(PRIMEVUE_PRESETS[value]);
}
