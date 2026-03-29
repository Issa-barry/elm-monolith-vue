import {
    updatePrimaryPalette,
    updateSurfacePalette,
    usePreset,
} from '@primeuix/styled';
import Aura from '@primeuix/themes/aura';
import Lara from '@primeuix/themes/lara';
import Material from '@primeuix/themes/material';
import Nora from '@primeuix/themes/nora';

export type PrimeVueThemeName =
    | 'aura'
    | 'lara'
    | 'material'
    | 'nora'
    | 'starter';
export type PrimeVuePrimaryName =
    | 'zinc'
    | 'emerald'
    | 'green'
    | 'lime'
    | 'yellow'
    | 'sky'
    | 'blue'
    | 'indigo'
    | 'violet'
    | 'purple'
    | 'fuchsia'
    | 'pink'
    | 'rose'
    | 'orange'
    | 'amber'
    | 'teal'
    | 'cyan';
export type PrimeVueSurfaceName =
    | 'zinc'
    | 'slate'
    | 'stone'
    | 'neutral'
    | 'gray';

export const PRIMEVUE_THEME_STORAGE_KEY = 'primevue_theme';
export const PRIMEVUE_PRIMARY_STORAGE_KEY = 'primevue_primary';
export const PRIMEVUE_SURFACE_STORAGE_KEY = 'primevue_surface';

const PRIMEVUE_PRESETS: Record<PrimeVueThemeName, object> = {
    aura: Aura,
    lara: Lara,
    material: Material,
    nora: Nora,
    starter: Aura,
};

const PRIMARY_PALETTES: Record<PrimeVuePrimaryName, Record<number, string>> = {
    zinc: {
        50: '#fafafa',
        100: '#f4f4f5',
        200: '#e4e4e7',
        300: '#d4d4d8',
        400: '#a1a1aa',
        500: '#71717a',
        600: '#52525b',
        700: '#3f3f46',
        800: '#27272a',
        900: '#18181b',
        950: '#09090b',
    },
    emerald: {
        50: '#ecfdf5',
        100: '#d1fae5',
        200: '#a7f3d0',
        300: '#6ee7b7',
        400: '#34d399',
        500: '#10b981',
        600: '#059669',
        700: '#047857',
        800: '#065f46',
        900: '#064e3b',
        950: '#022c22',
    },
    green: {
        50: '#f0fdf4',
        100: '#dcfce7',
        200: '#bbf7d0',
        300: '#86efac',
        400: '#4ade80',
        500: '#22c55e',
        600: '#16a34a',
        700: '#15803d',
        800: '#166534',
        900: '#14532d',
        950: '#052e16',
    },
    lime: {
        50: '#f7fee7',
        100: '#ecfccb',
        200: '#d9f99d',
        300: '#bef264',
        400: '#a3e635',
        500: '#84cc16',
        600: '#65a30d',
        700: '#4d7c0f',
        800: '#3f6212',
        900: '#365314',
        950: '#1a2e05',
    },
    yellow: {
        50: '#fefce8',
        100: '#fef9c3',
        200: '#fef08a',
        300: '#fde047',
        400: '#facc15',
        500: '#eab308',
        600: '#ca8a04',
        700: '#a16207',
        800: '#854d0e',
        900: '#713f12',
        950: '#422006',
    },
    sky: {
        50: '#f0f9ff',
        100: '#e0f2fe',
        200: '#bae6fd',
        300: '#7dd3fc',
        400: '#38bdf8',
        500: '#0ea5e9',
        600: '#0284c7',
        700: '#0369a1',
        800: '#075985',
        900: '#0c4a6e',
        950: '#082f49',
    },
    blue: {
        50: '#eff6ff',
        100: '#dbeafe',
        200: '#bfdbfe',
        300: '#93c5fd',
        400: '#60a5fa',
        500: '#3b82f6',
        600: '#2563eb',
        700: '#1d4ed8',
        800: '#1e40af',
        900: '#1e3a8a',
        950: '#172554',
    },
    indigo: {
        50: '#eef2ff',
        100: '#e0e7ff',
        200: '#c7d2fe',
        300: '#a5b4fc',
        400: '#818cf8',
        500: '#6366f1',
        600: '#4f46e5',
        700: '#4338ca',
        800: '#3730a3',
        900: '#312e81',
        950: '#1e1b4b',
    },
    violet: {
        50: '#f5f3ff',
        100: '#ede9fe',
        200: '#ddd6fe',
        300: '#c4b5fd',
        400: '#a78bfa',
        500: '#8b5cf6',
        600: '#7c3aed',
        700: '#6d28d9',
        800: '#5b21b6',
        900: '#4c1d95',
        950: '#2e1065',
    },
    purple: {
        50: '#faf5ff',
        100: '#f3e8ff',
        200: '#e9d5ff',
        300: '#d8b4fe',
        400: '#c084fc',
        500: '#a855f7',
        600: '#9333ea',
        700: '#7e22ce',
        800: '#6b21a8',
        900: '#581c87',
        950: '#3b0764',
    },
    fuchsia: {
        50: '#fdf4ff',
        100: '#fae8ff',
        200: '#f5d0fe',
        300: '#f0abfc',
        400: '#e879f9',
        500: '#d946ef',
        600: '#c026d3',
        700: '#a21caf',
        800: '#86198f',
        900: '#701a75',
        950: '#4a044e',
    },
    pink: {
        50: '#fdf2f8',
        100: '#fce7f3',
        200: '#fbcfe8',
        300: '#f9a8d4',
        400: '#f472b6',
        500: '#ec4899',
        600: '#db2777',
        700: '#be185d',
        800: '#9d174d',
        900: '#831843',
        950: '#500724',
    },
    rose: {
        50: '#fff1f2',
        100: '#ffe4e6',
        200: '#fecdd3',
        300: '#fda4af',
        400: '#fb7185',
        500: '#f43f5e',
        600: '#e11d48',
        700: '#be123c',
        800: '#9f1239',
        900: '#881337',
        950: '#4c0519',
    },
    orange: {
        50: '#fff7ed',
        100: '#ffedd5',
        200: '#fed7aa',
        300: '#fdba74',
        400: '#fb923c',
        500: '#f97316',
        600: '#ea580c',
        700: '#c2410c',
        800: '#9a3412',
        900: '#7c2d12',
        950: '#431407',
    },
    amber: {
        50: '#fffbeb',
        100: '#fef3c7',
        200: '#fde68a',
        300: '#fcd34d',
        400: '#fbbf24',
        500: '#f59e0b',
        600: '#d97706',
        700: '#b45309',
        800: '#92400e',
        900: '#78350f',
        950: '#451a03',
    },
    teal: {
        50: '#f0fdfa',
        100: '#ccfbf1',
        200: '#99f6e4',
        300: '#5eead4',
        400: '#2dd4bf',
        500: '#14b8a6',
        600: '#0d9488',
        700: '#0f766e',
        800: '#115e59',
        900: '#134e4a',
        950: '#042f2e',
    },
    cyan: {
        50: '#ecfeff',
        100: '#cffafe',
        200: '#a5f3fc',
        300: '#67e8f9',
        400: '#22d3ee',
        500: '#06b6d4',
        600: '#0891b2',
        700: '#0e7490',
        800: '#155e75',
        900: '#164e63',
        950: '#083344',
    },
};

const SURFACE_PALETTES: Record<PrimeVueSurfaceName, Record<number, string>> = {
    zinc: {
        50: '#fafafa',
        100: '#f4f4f5',
        200: '#e4e4e7',
        300: '#d4d4d8',
        400: '#a1a1aa',
        500: '#71717a',
        600: '#52525b',
        700: '#3f3f46',
        800: '#27272a',
        900: '#18181b',
        950: '#09090b',
    },
    slate: {
        50: '#f8fafc',
        100: '#f1f5f9',
        200: '#e2e8f0',
        300: '#cbd5e1',
        400: '#94a3b8',
        500: '#64748b',
        600: '#475569',
        700: '#334155',
        800: '#1e293b',
        900: '#0f172a',
        950: '#020617',
    },
    stone: {
        50: '#fafaf9',
        100: '#f5f5f4',
        200: '#e7e5e4',
        300: '#d6d3d1',
        400: '#a8a29e',
        500: '#78716c',
        600: '#57534e',
        700: '#44403c',
        800: '#292524',
        900: '#1c1917',
        950: '#0c0a09',
    },
    neutral: {
        50: '#fafafa',
        100: '#f5f5f5',
        200: '#e5e5e5',
        300: '#d4d4d4',
        400: '#a3a3a3',
        500: '#737373',
        600: '#525252',
        700: '#404040',
        800: '#262626',
        900: '#171717',
        950: '#0a0a0a',
    },
    gray: {
        50: '#f9fafb',
        100: '#f3f4f6',
        200: '#e5e7eb',
        300: '#d1d5db',
        400: '#9ca3af',
        500: '#6b7280',
        600: '#4b5563',
        700: '#374151',
        800: '#1f2937',
        900: '#111827',
        950: '#030712',
    },
};

export function normalizePrimeVueTheme(
    value?: string | null,
): PrimeVueThemeName {
    const normalized = value?.toLowerCase();

    if (normalized === 'starter') return 'starter';
    if (normalized === 'lara') return 'lara';
    if (normalized === 'material') return 'material';
    if (normalized === 'nora') return 'nora';

    return 'aura';
}

export function normalizePrimeVuePrimary(
    value?: string | null,
): PrimeVuePrimaryName {
    const normalized = value?.toLowerCase() as PrimeVuePrimaryName | undefined;

    return normalized && normalized in PRIMARY_PALETTES
        ? normalized
        : 'emerald';
}

export function normalizePrimeVueSurface(
    value?: string | null,
): PrimeVueSurfaceName {
    const normalized = value?.toLowerCase() as PrimeVueSurfaceName | undefined;

    return normalized && normalized in SURFACE_PALETTES ? normalized : 'zinc';
}

export function getPrimeVueThemePreset(value?: string | null): {
    name: PrimeVueThemeName;
    preset: object;
} {
    const name = normalizePrimeVueTheme(value);

    return {
        name,
        preset: PRIMEVUE_PRESETS[name],
    };
}

export function getDefaultPrimeVuePrimary(
    theme: PrimeVueThemeName,
): PrimeVuePrimaryName {
    return theme === 'starter' ? 'zinc' : 'emerald';
}

export function getDefaultPrimeVueSurface(
    _theme: PrimeVueThemeName,
): PrimeVueSurfaceName {
    return 'zinc';
}

export function resolvePrimeVueThemeFromEnv(): PrimeVueThemeName {
    return normalizePrimeVueTheme(
        import.meta.env.VITE_PRIMEVUE_THEME || 'aura',
    );
}

export function resolvePrimeVuePrimaryFromEnv(
    theme: PrimeVueThemeName,
): PrimeVuePrimaryName {
    return normalizePrimeVuePrimary(
        import.meta.env.VITE_PRIMEVUE_PRIMARY ||
            getDefaultPrimeVuePrimary(theme),
    );
}

export function resolvePrimeVueSurfaceFromEnv(
    theme: PrimeVueThemeName,
): PrimeVueSurfaceName {
    return normalizePrimeVueSurface(
        import.meta.env.VITE_PRIMEVUE_SURFACE ||
            getDefaultPrimeVueSurface(theme),
    );
}

export function getStoredPrimeVueTheme(): PrimeVueThemeName | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const value = localStorage.getItem(PRIMEVUE_THEME_STORAGE_KEY);

    return value ? normalizePrimeVueTheme(value) : null;
}

export function getStoredPrimeVuePrimary(): PrimeVuePrimaryName | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const value = localStorage.getItem(PRIMEVUE_PRIMARY_STORAGE_KEY);

    return value ? normalizePrimeVuePrimary(value) : null;
}

export function getStoredPrimeVueSurface(): PrimeVueSurfaceName | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const value = localStorage.getItem(PRIMEVUE_SURFACE_STORAGE_KEY);

    return value ? normalizePrimeVueSurface(value) : null;
}

export function setStoredPrimeVueTheme(value: PrimeVueThemeName) {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(PRIMEVUE_THEME_STORAGE_KEY, value);
}

export function setStoredPrimeVuePrimary(value: PrimeVuePrimaryName) {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(PRIMEVUE_PRIMARY_STORAGE_KEY, value);
}

export function setStoredPrimeVueSurface(value: PrimeVueSurfaceName) {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(PRIMEVUE_SURFACE_STORAGE_KEY, value);
}

export function applyPrimeVueThemePreset(value: PrimeVueThemeName) {
    usePreset(PRIMEVUE_PRESETS[value]);
}

export function applyPrimeVuePrimaryColor(value: PrimeVuePrimaryName) {
    updatePrimaryPalette(PRIMARY_PALETTES[value]);
}

export function applyPrimeVueSurfaceColor(value: PrimeVueSurfaceName) {
    const scale = SURFACE_PALETTES[value];

    updateSurfacePalette({
        light: scale,
        dark: scale,
    });
}

export function applyAppThemeColors(
    primary: PrimeVuePrimaryName,
    surface: PrimeVueSurfaceName,
    isDark: boolean,
) {
    if (typeof document === 'undefined') {
        return;
    }

    const p = PRIMARY_PALETTES[primary];
    const s = SURFACE_PALETTES[surface];
    const root = document.documentElement;

    const tokens = isDark
        ? {
              '--background': s[950],
              '--foreground': s[50],
              '--card': s[900],
              '--card-foreground': s[50],
              '--popover': s[900],
              '--popover-foreground': s[50],
              '--primary': p[400],
              '--primary-foreground': s[950],
              '--secondary': s[800],
              '--secondary-foreground': s[50],
              '--muted': s[800],
              '--muted-foreground': s[300],
              '--accent': s[800],
              '--accent-foreground': s[50],
              '--border': s[800],
              '--input': s[800],
              '--ring': p[400],
              '--chart-1': p[400],
              '--chart-2': p[500],
              '--chart-3': p[600],
              '--chart-4': p[300],
              '--chart-5': p[200],
              '--sidebar-background': s[950],
              '--sidebar-foreground': s[100],
              '--sidebar-primary': p[500],
              '--sidebar-primary-foreground': s[950],
              '--sidebar-accent': s[900],
              '--sidebar-accent-foreground': s[100],
              '--sidebar-border': s[800],
              '--sidebar-ring': p[400],
              '--sidebar': s[900],
          }
        : {
              '--background': '#ffffff',
              '--foreground': s[950],
              '--card': '#ffffff',
              '--card-foreground': s[950],
              '--popover': '#ffffff',
              '--popover-foreground': s[950],
              '--primary': p[600],
              '--primary-foreground': '#ffffff',
              '--secondary': s[200],
              '--secondary-foreground': s[800],
              '--muted': s[100],
              '--muted-foreground': s[500],
              '--accent': s[100],
              '--accent-foreground': s[800],
              '--border': s[200],
              '--input': s[300],
              '--ring': p[500],
              '--chart-1': p[500],
              '--chart-2': p[400],
              '--chart-3': p[300],
              '--chart-4': p[600],
              '--chart-5': p[700],
              '--sidebar-background': s[50],
              '--sidebar-foreground': s[700],
              '--sidebar-primary': p[600],
              '--sidebar-primary-foreground': '#ffffff',
              '--sidebar-accent': s[100],
              '--sidebar-accent-foreground': s[800],
              '--sidebar-border': s[200],
              '--sidebar-ring': p[500],
              '--sidebar': s[50],
          };

    Object.entries(tokens).forEach(([name, value]) => {
        root.style.setProperty(name, value);
    });
}

export function applyStoredPrimeVueColors(theme?: PrimeVueThemeName): {
    primary: PrimeVuePrimaryName;
    surface: PrimeVueSurfaceName;
} {
    const currentTheme = theme ?? resolvePrimeVueThemeFromEnv();
    const primary =
        getStoredPrimeVuePrimary() ??
        resolvePrimeVuePrimaryFromEnv(currentTheme);
    const surface =
        getStoredPrimeVueSurface() ??
        resolvePrimeVueSurfaceFromEnv(currentTheme);

    applyPrimeVuePrimaryColor(primary);
    applyPrimeVueSurfaceColor(surface);

    return {
        primary,
        surface,
    };
}
