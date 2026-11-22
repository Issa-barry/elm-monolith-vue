import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Pagination et tri renvoyés par le serveur (ex. ListeFactures, ListeVehicules). */
export interface PaginationServeur {
    page: number;
    par_page: number;
    total: number;
    derniere_page: number;
    tri: string | null;
    sens: 'asc' | 'desc' | null;
}

interface EvenementTri {
    sortField?: unknown;
    sortOrder?: number | null;
}

/**
 * Liste paginée et triée par le serveur : paramètres de la DataTable PrimeVue en mode lazy et
 * rechargement Inertia de la page demandée. Les filtres de l'URL sont conservés ; un changement
 * de tri revient à la première page.
 */
export function usePaginationServeur(pagination: () => PaginationServeur) {
    function recharger(
        page: number,
        tri: string | null,
        sens: string | null,
    ): void {
        const data: Record<string, string | number | undefined> = {
            page: undefined,
            tri: undefined,
            sens: undefined,
        };
        if (page > 1) data.page = page;
        if (tri) {
            data.tri = tri;
            data.sens = sens ?? undefined;
        }
        router.reload({ data });
    }

    function changerPage(event: { page: number }): void {
        const { tri, sens } = pagination();
        recharger(event.page + 1, tri, sens);
    }

    function changerTri(event: EvenementTri): void {
        const champ =
            typeof event.sortField === 'string' && event.sortOrder
                ? event.sortField
                : null;
        recharger(1, champ, event.sortOrder === 1 ? 'asc' : 'desc');
    }

    const tableauServeur = computed(() => {
        const p = pagination();
        let sortOrder: 1 | -1 | undefined;
        if (p.tri) sortOrder = p.sens === 'asc' ? 1 : -1;

        return {
            lazy: true,
            paginator: p.total > p.par_page,
            rows: p.par_page,
            totalRecords: p.total,
            first: (p.page - 1) * p.par_page,
            sortField: p.tri ?? undefined,
            sortOrder,
        };
    });

    return { changerPage, changerTri, tableauServeur };
}
