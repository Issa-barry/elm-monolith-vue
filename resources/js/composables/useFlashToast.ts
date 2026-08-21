import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, watch } from 'vue';

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

    watch(flashSuccess, (msg) => {
        if (msg) {
            toast.add({
                group,
                severity: 'success',
                summary: 'Succès',
                detail: msg,
                life: 4000,
            });
        }
    });

    watch(flashError, (msg) => {
        if (msg) {
            toast.add({
                group,
                severity: 'error',
                summary: 'Erreur',
                detail: msg,
                life: 5000,
            });
        }
    });
}
