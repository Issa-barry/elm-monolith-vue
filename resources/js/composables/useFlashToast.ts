import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, watch } from 'vue';

/**
 * Affiche le flash `success`/`error` d'Inertia sous forme de toast — mirroring du pattern déjà
 * répété manuellement dans plusieurs pages (Sites/Show.vue, etc.), factorisé ici pour les
 * nouveaux écrans fonctions RH / employés / validation de compte, qui doivent tous utiliser le
 * groupe "top" (haut à droite, cf. AppSidebarLayout.vue) plutôt que le groupe par défaut
 * (bas à droite) utilisé ailleurs dans l'application.
 */
export function useFlashToast(group: string = 'top') {
    const page = usePage();
    const toast = useToast();

    const flashSuccess = computed(
        () => (page.props as any).flash?.success as string | undefined,
    );
    const flashError = computed(
        () => (page.props as any).flash?.error as string | undefined,
    );

    function showSuccess(msg: string) {
        toast.add({
            group,
            severity: 'success',
            summary: 'Succès',
            detail: msg,
            life: 4000,
        });
    }

    function showError(msg: string) {
        toast.add({
            group,
            severity: 'error',
            summary: 'Erreur',
            detail: msg,
            life: 5000,
        });
    }

    // Update sur place (PUT/PATCH restant sur la même page) : flash.success/.error CHANGE, le
    // watcher suffit.
    watch(flashSuccess, (msg) => msg && showSuccess(msg));
    watch(flashError, (msg) => msg && showError(msg));

    // Navigation Inertia FRAÎCHE (ex: redirect après création vers une autre page) : flash est
    // déjà présent dès le tout premier rendu du nouveau composant, jamais "changé" ensuite — un
    // `watch(..., { immediate: true })` ne suffit pas ici : il s'exécute pendant `setup()`, avant
    // que le <Toast group="top"> du layout (monté comme enfant) ait eu la chance de s'abonner au
    // bus d'événements de PrimeVue — le toast est alors émis dans le vide et silencieusement
    // perdu. `onMounted` s'exécute après le montage complet du sous-arbre (layout + Toast inclus),
    // le bus est déjà prêt à recevoir.
    onMounted(() => {
        if (flashSuccess.value) showSuccess(flashSuccess.value);
        if (flashError.value) showError(flashError.value);
    });
}
