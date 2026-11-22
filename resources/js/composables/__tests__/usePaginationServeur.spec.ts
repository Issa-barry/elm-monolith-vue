import {
    usePaginationServeur,
    type PaginationServeur,
} from '@/composables/usePaginationServeur';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { reload } }));

const pagination = (p: Partial<PaginationServeur> = {}): PaginationServeur => ({
    page: 2,
    par_page: 20,
    total: 95,
    derniere_page: 5,
    tri: 'montant_net',
    sens: 'desc',
    ...p,
});

describe('usePaginationServeur', () => {
    beforeEach(() => reload.mockClear());

    it('donne à la DataTable lazy la page, le total et le tri du serveur', () => {
        const { tableauServeur } = usePaginationServeur(() => pagination());

        expect(tableauServeur.value).toEqual({
            lazy: true,
            paginator: true,
            rows: 20,
            totalRecords: 95,
            first: 20,
            sortField: 'montant_net',
            sortOrder: -1,
        });
    });

    it('sans tri ni seconde page, ni tri ni pagination affichés', () => {
        const { tableauServeur } = usePaginationServeur(() =>
            pagination({ page: 1, total: 12, tri: null, sens: null }),
        );

        expect(tableauServeur.value.paginator).toBe(false);
        expect(tableauServeur.value.sortField).toBeUndefined();
        expect(tableauServeur.value.sortOrder).toBeUndefined();
    });

    it('changer de page garde le tri en cours', () => {
        const { changerPage } = usePaginationServeur(() =>
            pagination({ tri: 'reference', sens: 'asc' }),
        );

        changerPage({ page: 3 });

        expect(reload).toHaveBeenCalledWith({
            data: { page: 4, tri: 'reference', sens: 'asc' },
        });
    });

    it('revenir à la première page retire le paramètre page', () => {
        const { changerPage } = usePaginationServeur(() =>
            pagination({ tri: null, sens: null }),
        );

        changerPage({ page: 0 });

        expect(reload).toHaveBeenCalledWith({
            data: { page: undefined, tri: undefined, sens: undefined },
        });
    });

    it('changer de tri revient à la première page ; sans ordre, le tri est retiré', () => {
        const { changerTri } = usePaginationServeur(() => pagination());

        changerTri({ sortField: 'created_at', sortOrder: 1 });
        changerTri({ sortField: 'created_at', sortOrder: null });

        expect(reload).toHaveBeenNthCalledWith(1, {
            data: { page: undefined, tri: 'created_at', sens: 'asc' },
        });
        expect(reload).toHaveBeenNthCalledWith(2, {
            data: { page: undefined, tri: undefined, sens: undefined },
        });
    });
});
