import { useAppearance } from '@/composables/useAppearance';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Thin wrapper around useAppearance that exposes `getPrimary`, `getSurface`,
 * and `isDarkTheme` as reactive refs — the same shape that Apollo dashboard
 * widgets expect from `useLayout`, which doesn't exist in this project.
 */
export function useChartTheme() {
    const { primeVuePrimary, primeVueSurface } = useAppearance();

    const isDark = ref(false);

    function checkDark() {
        isDark.value =
            typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark');
    }

    let observer: MutationObserver | null = null;

    onMounted(() => {
        checkDark();
        observer = new MutationObserver(checkDark);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onUnmounted(() => {
        observer?.disconnect();
    });

    return {
        getPrimary: computed(() => primeVuePrimary.value),
        getSurface: computed(() => primeVueSurface.value),
        isDarkTheme: isDark,
    };
}
