import { useEnvironmentTheme } from '@/composables/useEnvironmentTheme';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Thin wrapper around useEnvironmentTheme that exposes `getPrimary`,
 * `getSurface`, and `isDarkTheme` as reactive refs — the same shape that
 * Apollo dashboard widgets expect from `useLayout`, which doesn't exist in
 * this project. Primary/surface come from the environment theme (global,
 * admin-set — cf. useEnvironmentTheme.ts), not useAppearance() (personal
 * light/dark preference only): that's the reactive trigger these widgets
 * need to recompute their colors when the theme changes.
 */
export function useChartTheme() {
    const { active } = useEnvironmentTheme();

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
        getPrimary: computed(() => active.value.primary),
        getSurface: computed(() => active.value.surface),
        isDarkTheme: isDark,
    };
}
