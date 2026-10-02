import SituationShow from '@/pages/Comptabilite/Tresorerie/Situation/Show.vue';
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

const support = (surcharge: Record<string, unknown> = {}) => ({
    compte_tresorerie_id: 'c1',
    site_id: 's1',
    libelle: 'Caisse principale',
    type: 'caisse',
    solde: 0,
    en_cours_versement: 0,
    versements_en_cours: 0,
    ...surcharge,
});

const monter = (
    supports: ReturnType<typeof support>[],
    enCours = { montant: 0, nombre: 0 },
) =>
    shallowMount(SituationShow, {
        props: {
            site: { id: 's1', nom: 'Matoto' },
            supports,
            total: supports.reduce((somme, s) => somme + Number(s.solde), 0),
            en_cours_versement: enCours.montant,
            versements_en_cours: enCours.nombre,
            filters: { date: '2026-09-20' },
        },
        global: { renderStubDefaultSlot: true },
    });

describe("Situation d'une agence — versements en cours", () => {
    it("n'affiche aucune information de versement sans versement en cours", () => {
        const wrapper = monter([support({ solde: 850_000 })]);

        expect(
            wrapper
                .find('[data-testid="situation-en-cours-versement"]')
                .exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="situation-support-en-cours"]').exists(),
        ).toBe(false);
    });

    it('détaille le versement en cours sous la caisse qui verse, sans changer son solde ni le total', () => {
        const wrapper = monter(
            [
                support({
                    compte_tresorerie_id: 'c-agent',
                    libelle: 'Caisse Saa Fodé',
                    solde: 50_000,
                    en_cours_versement: 800_000,
                    versements_en_cours: 1,
                }),
                support({ compte_tresorerie_id: 'c-agence', solde: 0 }),
            ],
            { montant: 800_000, nombre: 1 },
        );

        expect(
            wrapper.find('[data-testid="situation-en-cours-versement"]').text(),
        ).toContain('800 000 GNF en cours de versement');

        const [agent, agence] = wrapper.findAll('tbody tr');
        expect(agent.text()).toContain('50 000 GNF');
        expect(agent.text()).not.toContain('850 000');
        expect(
            agent.find('[data-testid="situation-support-en-cours"]').text(),
        ).toBe('En cours de versement : 800 000 GNF');
        expect(
            agence.find('[data-testid="situation-support-en-cours"]').exists(),
        ).toBe(false);
        // Total de l'agence = grand livre : 50 000 (le versement est en transit).
        expect(wrapper.find('tfoot').text()).toContain('50 000 GNF');
    });
});
