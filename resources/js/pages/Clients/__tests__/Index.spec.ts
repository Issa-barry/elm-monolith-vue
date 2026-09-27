import DataFilters from '@/components/filters/DataFilters.vue';
import ClientsIndex from '@/pages/Clients/Index.vue';
import { shallowMount } from '@vue/test-utils';
import DataTable from 'primevue/datatable';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: () => true }),
}));
vi.mock('primevue/useconfirm', () => ({
    useConfirm: () => ({ require: vi.fn() }),
}));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const filters = {
    type: 'grossiste',
    cashback: 'non_eligible',
    statut: 'actif',
    recherche: 'Diallo',
};
const client = {
    id: 'client-1',
    nom: 'Diallo',
    prenom: 'Fatoumata',
    nom_complet: 'Fatoumata Diallo',
    email: null,
    telephone: null,
    code_phone_pays: null,
    ville: null,
    pays: null,
    code_pays: null,
    adresse: null,
    is_active: true,
    type: 'grossiste',
    type_label: 'Grossiste',
    cashback_eligible: false,
    cashback_montant_par_pack: null,
};
const monter = () =>
    shallowMount(ClientsIndex, {
        props: {
            clients: [client],
            filters,
            types: [{ value: 'grossiste', label: 'Grossiste' }],
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: {
                DataTable: {
                    props: ['value'],
                    template: '<div><slot /></div>',
                },
            },
        },
    });

describe('Clients — filtres serveur', () => {
    it('partage les filtres appliqués et la même URL entre mobile et ordinateur', () => {
        const composants = monter().findAllComponents(DataFilters);
        expect(composants).toHaveLength(2);
        for (const composant of composants) {
            expect(composant.props('url')).toBe('/backoffice/clients');
            expect(composant.props('values')).toEqual(filters);
        }
        expect(composants[0].props('triggerOnly')).toBe(true);
        expect(composants[1].props('fields').slice(0, 2)).toEqual([
            expect.objectContaining({
                key: 'type',
                inline: true,
                options: [{ value: 'grossiste', label: 'Grossiste' }],
            }),
            expect.objectContaining({
                key: 'cashback',
                inline: true,
                options: [
                    { value: 'eligible', label: 'Éligible' },
                    { value: 'non_eligible', label: 'Non éligible' },
                ],
            }),
        ]);
    });

    it('affiche les résultats reçus du serveur et suit leur actualisation', async () => {
        const wrapper = monter();
        expect(wrapper.findComponent(DataTable).props('value')).toEqual([
            client,
        ]);
        await wrapper.setProps({
            clients: [],
            filters: { ...filters, cashback: 'eligible' },
        });
        expect(wrapper.findComponent(DataTable).props('value')).toEqual([]);
        for (const composant of wrapper.findAllComponents(DataFilters)) {
            expect(composant.props('resultCount')).toBe(0);
            expect(composant.props('values').cashback).toBe('eligible');
        }
    });
});
