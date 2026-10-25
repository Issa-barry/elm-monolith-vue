import type { FilterField } from '@/components/filters/DataFilters.vue';
import QuickFilters from '@/components/filters/QuickFilters.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
}));

const champs: FilterField[] = [
    {
        key: 'recherche',
        label: 'Rechercher une vente',
        type: 'text',
        placeholder: 'Rechercher une vente…',
        inline: true,
    },
    {
        key: 'numero_commande',
        label: 'N° commande',
        type: 'text',
        placeholder: 'VTE-…, DST-…',
        inline: true,
    },
    { key: 'livreur', label: 'Livreur', type: 'text', inline: true },
    { key: 'vehicule', label: 'Véhicule', type: 'text', inline: true },
];

const monter = (values: Record<string, unknown> = {}) =>
    mount(QuickFilters, {
        props: {
            url: '/backoffice/ventes',
            fields: champs,
            searchKey: 'recherche',
            values,
            baseParams: { periode: 'all' },
            savedFilterScope: 'ventes',
            debounce: 0,
        },
    });

const champ = (wrapper: ReturnType<typeof monter>, cle: string) =>
    wrapper.get(`[data-testid="quick-filter-${cle}"]`);

const valeur = (wrapper: ReturnType<typeof monter>, cle: string) =>
    (champ(wrapper, cle).element as HTMLInputElement).value;

describe('QuickFilters — barre rapide du tableau', () => {
    beforeEach(() => routerGet.mockClear());

    it('affiche la recherche puis N° commande, Livreur et Véhicule, sans libellé « search »', () => {
        const wrapper = monter();

        const champsAffiches = wrapper
            .findAll('input')
            .map((i) => i.attributes('data-testid'));
        expect(champsAffiches).toEqual([
            'quick-filter-recherche',
            'quick-filter-numero_commande',
            'quick-filter-livreur',
            'quick-filter-vehicule',
        ]);
        expect(champ(wrapper, 'recherche').attributes('placeholder')).toBe(
            'Rechercher une vente…',
        );
        expect(champ(wrapper, 'livreur').attributes('aria-label')).toBe(
            'Livreur',
        );
        expect(wrapper.find('[role="search"]').exists()).toBe(true);
    });

    it('restitue les valeurs appliquées par le serveur (URL)', () => {
        const wrapper = monter({ recherche: 'Soumah', livreur: 'Diallo' });

        expect(valeur(wrapper, 'recherche')).toBe('Soumah');
        expect(valeur(wrapper, 'livreur')).toBe('Diallo');
        expect(valeur(wrapper, 'vehicule')).toBe('');
    });

    it('envoie la saisie au serveur en conservant les filtres du panneau', async () => {
        const wrapper = monter({
            statuts: ['livree'],
            site_ids: ['matoto'],
            statut_facture: 'impayee',
            livreur: 'Diallo',
            client: null,
            en_retard: null,
        });

        await champ(wrapper, 'vehicule').setValue('RC-4521');
        await new Promise((r) => setTimeout(r, 5));

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            '/backoffice/ventes',
            {
                periode: 'all',
                statuts: ['livree'],
                site_ids: ['matoto'],
                statut_facture: 'impayee',
                livreur: 'Diallo',
                vehicule: 'RC-4521',
                all: '1',
            },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('applique immédiatement avec la touche Entrée', async () => {
        const wrapper = mount(QuickFilters, {
            props: {
                url: '/backoffice/ventes',
                fields: champs,
                searchKey: 'recherche',
                values: {},
                debounce: 10_000,
            },
        });

        await champ(wrapper, 'numero_commande').setValue('VTE-0042');
        await champ(wrapper, 'numero_commande').trigger('keydown', {
            key: 'Enter',
        });

        expect(routerGet).toHaveBeenCalledWith(
            '/backoffice/ventes',
            { numero_commande: 'VTE-0042' },
            expect.anything(),
        );
    });

    it('efface un seul critère sans toucher aux autres', async () => {
        const wrapper = monter({
            recherche: 'Soumah',
            livreur: 'Diallo',
            statuts: ['livree'],
        });

        await wrapper
            .get('[data-testid="quick-filter-clear-livreur"]')
            .trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/backoffice/ventes',
            {
                periode: 'all',
                statuts: ['livree'],
                recherche: 'Soumah',
                all: '1',
            },
            expect.anything(),
        );
    });

    it('« Effacer » vide la barre rapide mais garde les filtres du panneau', async () => {
        const wrapper = monter({
            recherche: 'Soumah',
            vehicule: 'RC',
            statuts: ['livree'],
        });

        await wrapper
            .get('[data-testid="quick-filters-reset"]')
            .trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/backoffice/ventes',
            { periode: 'all', statuts: ['livree'], all: '1' },
            expect.anything(),
        );
    });

    it('n’affiche « Effacer » que lorsqu’un filtre rapide est appliqué', () => {
        expect(
            monter({ statuts: ['livree'] })
                .find('[data-testid="quick-filters-reset"]')
                .exists(),
        ).toBe(false);
        expect(
            monter({ livreur: 'Diallo' })
                .find('[data-testid="quick-filters-reset"]')
                .exists(),
        ).toBe(true);
    });

    it('ne renvoie pas de requête quand la valeur n’a pas changé', async () => {
        const wrapper = monter({ livreur: 'Diallo' });

        await champ(wrapper, 'livreur').setValue('Diallo ');
        await new Promise((r) => setTimeout(r, 5));

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('suit les filtres renvoyés par le serveur après une application du panneau', async () => {
        const wrapper = monter({ livreur: 'Diallo' });

        await wrapper.setProps({ values: { livreur: '', vehicule: 'RC-1' } });
        await nextTick();

        expect(valeur(wrapper, 'livreur')).toBe('');
        expect(valeur(wrapper, 'vehicule')).toBe('RC-1');
    });
});
