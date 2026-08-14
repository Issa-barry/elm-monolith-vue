import { applyEnvironmentTheme } from '@/composables/useEnvironmentTheme';
import { usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

// Préférence PERSONNELLE (light/dark/system) — stockée en localStorage/cookie,
// propre à chaque utilisateur. Ne pas confondre avec le thème global
// (preset/couleur principale/surface), décidé par un admin et partagé par
// tout l'environnement : cf. composables/useEnvironmentTheme.ts et
// docs/theming.md.

type Appearance = 'light' | 'dark' | 'system';

const updateFavicon = (isDark: boolean) => {
    const favicon = document.querySelector<HTMLLinkElement>(
        'link[rel="icon"][type="image/svg+xml"]',
    );

    if (favicon) {
        favicon.href = isDark ? '/favicon-dark.svg' : '/favicon.svg';
    }
};

/**
 * Réapplique le thème global (couleurs de l'environnement) pour l'état
 * light/dark courant. Best-effort : au tout premier appel (avant que les
 * props Inertia ne soient hydratées), `theme` peut être absent — le prochain
 * changement d'apparence ou la synchro de useEnvironmentTheme() prendra le
 * relais.
 */
const reapplyEnvironmentTheme = () => {
    // Optional chaining volontaire malgré le typage non-optionnel de `props` :
    // avant que `createInertiaApp` n'ait hydraté `page.value` (tout premier
    // appel, déclenché par initializeTheme() dans app.ts), `props` est
    // réellement `undefined` au runtime.
    const theme = usePage().props?.theme;
    if (theme) {
        applyEnvironmentTheme(theme.active);
    }
};

export function updateTheme(value: Appearance) {
    if (typeof window === 'undefined') {
        return;
    }

    let isDark: boolean;

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        isDark = mediaQueryList.matches;
    } else {
        isDark = value === 'dark';
    }

    document.documentElement.classList.toggle('dark', isDark);
    updateFavicon(isDark);
    reapplyEnvironmentTheme();
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('appearance') as Appearance | null;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'light');
};

export function initializeTheme() {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to light...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'light');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('light');

export function useAppearance() {
    onMounted(() => {
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        updateAppearance,
    };
}
