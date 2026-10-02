import SituationIndex from '@/pages/Comptabilite/Tresorerie/Situation/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/AppLayout.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
    };
});
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
    };
});

type Ligne = {
    site_id: string;
    site_nom: string;
    par_type: Record<string, number>;
    total: number;
    en_cours_versement: number;
    versements_en_cours: number;
};

const ligne = (surcharge: Partial<Ligne> = {}): Ligne => ({
    site_id: 's1',
    site_nom: 'Matoto',
    par_type: { caisse: 50_000, banque: 0, mobile_money: 0 },
    total: 50_000,
    en_cours_versement: 0,
    versements_en_cours: 0,
    ...surcharge,
});

const monter = (rows: Ligne[]) =>
    shallowMount(SituationIndex, {
        props: {
            rows,
            total_general: {
                par_type: { caisse: 50_000, banque: 0, mobile_money: 0 },
                total: rows.reduce((somme, r) => somme + r.total, 0),
                en_cours_versement: rows.reduce(
                    (somme, r) => somme + r.en_cours_versement,
                    0,
                ),
                versements_en_cours: rows.reduce(
                    (somme, r) => somme + r.versements_en_cours,
                    0,
                ),
            },
            type_options: [
                { value: 'caisse', label: 'Caisse' },
                { value: 'banque', label: 'Banque' },
                { value: 'mobile_money', label: 'Mobile Money' },
            ],
            filters: { date: '2026-09-20', site_ids: [] },
            sites: [],
            is_admin: true,
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Situation de trésorerie — versements en cours', () => {
    it("n'affiche aucune information de versement sans versement en cours", () => {
        const wrapper = monter([ligne()]);

        expect(
            wrapper
                .find('[data-testid="situation-en-cours-versement"]')
                .exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="situation-site-en-cours"]').exists(),
        ).toBe(false);
    });

    it("explique le total qui baisse à l'envoi, sans l'ajouter aux soldes", () => {
        const wrapper = monter([
            ligne({ en_cours_versement: 800_000, versements_en_cours: 1 }),
        ]);

        const information = wrapper.find(
            '[data-testid="situation-en-cours-versement"]',
        );
        expect(information.text()).toContain(
            '800 000 GNF en cours de versement',
        );
        expect(information.text()).toContain('1 versement');
        expect(information.text()).toContain("n'est plus dans les soldes");

        // Le total de la ligne reste celui du grand livre : 50 000, jamais 850 000.
        const ligneAgence = wrapper.find('tbody tr');
        expect(ligneAgence.text()).toContain('50 000 GNF');
        expect(ligneAgence.text()).not.toContain('850 000');
        expect(
            ligneAgence.find('[data-testid="situation-site-en-cours"]').text(),
        ).toBe('En cours de versement : 800 000 GNF');
    });

    it('accorde le pluriel quand plusieurs versements attendent', () => {
        const wrapper = monter([
            ligne({ en_cours_versement: 500_000, versements_en_cours: 2 }),
        ]);

        expect(
            wrapper.find('[data-testid="situation-en-cours-versement"]').text(),
        ).toContain('2 versements');
    });
});
