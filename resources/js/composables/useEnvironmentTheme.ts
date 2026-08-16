import {
    applyAppThemeColors,
    applyPrimeVuePrimaryColor,
    applyPrimeVueSurfaceColor,
    applyPrimeVueThemePreset,
    type PrimeVuePrimaryName,
    type PrimeVueSurfaceName,
    type PrimeVueThemeName,
} from '@/lib/primevue-theme';
import type { ThemeActive } from '@/types/theme';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Thème global (preset PrimeVue / couleur principale / surface) : décidé par
 * un admin, partagé par tous les utilisateurs de l'environnement, résolu
 * côté serveur (ThemePolicyService, prop Inertia `theme`). Distinct
 * d'`useAppearance()` (light/dark/system), qui reste une préférence
 * personnelle en localStorage — cf. docs/theming.md.
 */

/**
 * Optional chaining volontaire malgré le typage non-optionnel de `props` :
 * `watchEnvironmentTheme()` est câblé avant `app.mount()` dans app.ts, donc sa
 * toute première évaluation peut tomber avant que `page.value` interne
 * d'Inertia ne soit hydraté.
 */
function pageThemeActive(): ThemeActive | undefined {
    return usePage().props?.theme?.active;
}

export function applyEnvironmentTheme(active: ThemeActive) {
    if (typeof document === 'undefined') {
        return;
    }

    applyPrimeVueThemePreset(active.preset);
    applyPrimeVuePrimaryColor(active.primary);
    applyPrimeVueSurfaceColor(active.surface);
    applyAppThemeColors(
        active.primary,
        active.surface,
        document.documentElement.classList.contains('dark'),
    );
}

let watcherStarted = false;

/**
 * À appeler une fois au boot (cf. app.ts), après l'application initiale du
 * thème. Tient les couleurs synchronisées à chaque changement du prop
 * `theme` partagé : navigation Inertia classique, ou retour de succès après
 * qu'un admin ait changé le thème (useEnvironmentTheme().update() plus bas) —
 * un seul mécanisme couvre les deux cas, pas de rappel manuel à ajouter.
 */
export function watchEnvironmentTheme() {
    if (watcherStarted) {
        return;
    }
    watcherStarted = true;

    watch(
        pageThemeActive,
        (active) => {
            if (active) {
                applyEnvironmentTheme(active);
            }
        },
        { deep: true },
    );
}

export function useEnvironmentTheme() {
    const page = usePage();

    const active = computed(() => page.props.theme.active);
    const allowed = computed(() => page.props.theme.allowed);
    const locked = computed(() => page.props.theme.locked);
    const processing = ref(false);

    /**
     * Le serveur reste l'autorité : cet appel ne fait qu'envoyer la demande,
     * la validation contre la politique (UpdateThemeRequest) peut la refuser
     * (422) même si l'IHM n'aurait pas dû permettre de la formuler.
     */
    function update(next: {
        preset: PrimeVueThemeName;
        primary: PrimeVuePrimaryName;
        surface: PrimeVueSurfaceName;
    }): void {
        if (processing.value) {
            return;
        }
        processing.value = true;

        router.put('/settings/theme', next, {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        });
    }

    return { active, allowed, locked, processing, update };
}
