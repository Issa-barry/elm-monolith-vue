import { onMounted, ref } from 'vue';
import {
    applyPrimeVueThemePreset,
    getStoredPrimeVueTheme,
    resolvePrimeVueThemeFromEnv,
    setStoredPrimeVueTheme,
    type PrimeVueThemeName,
} from '@/lib/primevue-theme';

type Appearance = 'light' | 'dark' | 'system';

export function updateTheme(value: Appearance) {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
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

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');
const primeVueTheme = ref<PrimeVueThemeName>(resolvePrimeVueThemeFromEnv());

export function useAppearance() {
    onMounted(() => {
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }

        const savedPrimeVueTheme = getStoredPrimeVueTheme();
        primeVueTheme.value = savedPrimeVueTheme ?? resolvePrimeVueThemeFromEnv();
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    function updatePrimeVueTheme(value: PrimeVueThemeName) {
        primeVueTheme.value = value;

        setStoredPrimeVueTheme(value);
        setCookie('primevue_theme', value);
        applyPrimeVueThemePreset(value);
    }

    return {
        appearance,
        updateAppearance,
        primeVueTheme,
        updatePrimeVueTheme,
    };
}
