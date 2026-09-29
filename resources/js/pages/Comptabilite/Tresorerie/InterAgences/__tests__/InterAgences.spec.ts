import { Checkbox } from '@/components/ui/checkbox';
import InterAgencesIndex from '@/pages/Comptabilite/Tresorerie/InterAgences/Index.vue';
import ReglementDialog from '@/pages/Comptabilite/Tresorerie/InterAgences/partials/ReglementDialog.vue';
import { router } from '@inertiajs/vue3';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

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
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        router: { post: vi.fn(), get: vi.fn() },
    };
});

// ── Fenêtre de règlement ─────────────────────────────────────────────────────

const LIGNES = [
    {
        encaissement_id: 'e1',
        facture_reference: 'CMD-001',
        client_nom: 'Mamadou Diallo',
        date_encaissement: '2026-09-29',
        montant: 200_000,
        selectionnable: true,
    },
    {
        encaissement_id: 'e2',
        facture_reference: 'CMD-002',
        client_nom: 'Aïssatou Bah',
        date_encaissement: '2026-09-29',
        montant: 150_000,
        selectionnable: true,
    },
];

const SUPPORTS = [
    {
        id: 's-orange',
        libelle: 'Orange Money de Kindia',
        type: 'mobile_money',
        solde: 1_000_000,
    },
    {
        id: 's-caisse',
        libelle: 'Caisse Kindia',
        type: 'caisse',
        solde: 100_000,
    },
];

const monterDialog = (props: Record<string, unknown> = {}) =>
    shallowMount(ReglementDialog, {
        props: {
            open: true,
            lignes: LIGNES,
            supports: SUPPORTS,
            debiteur: { id: 'b', nom: 'Kindia' },
            creancier: { id: 'a', nom: 'Matoto' },
            ...props,
        },
        global: { renderStubDefaultSlot: true },
    });

const envoyer = (wrapper: ReturnType<typeof monterDialog>) =>
    wrapper.get('[data-testid="reglement-envoyer"]');

describe('ReglementDialog — règlement inter-agences', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('coche par défaut toutes les lignes « À verser » et en affiche le total calculé', () => {
        const wrapper = monterDialog();

        expect(wrapper.findAll('[data-testid="ligne-reglable"]')).toHaveLength(
            2,
        );
        expect(wrapper.get('[data-testid="reglement-total"]').text()).toBe(
            '350 000 GNF',
        );
    });

    it('ne propose aucune ligne non sélectionnable', () => {
        const wrapper = monterDialog({
            lignes: [LIGNES[0], { ...LIGNES[1], selectionnable: false }],
        });

        expect(wrapper.findAll('[data-testid="ligne-reglable"]')).toHaveLength(
            1,
        );
        expect(wrapper.get('[data-testid="reglement-total"]').text()).toBe(
            '200 000 GNF',
        );
    });

    it('recalcule le total quand une ligne est décochée', async () => {
        const wrapper = monterDialog();

        const caseLigne = wrapper
            .findAllComponents(Checkbox)
            .find((c) => c.attributes('aria-label') === 'Régler CMD-002')!;
        caseLigne.vm.$emit('update:modelValue', false);
        await nextTick();

        expect(wrapper.get('[data-testid="reglement-total"]').text()).toBe(
            '200 000 GNF',
        );
    });

    it('n’offre aucun champ de montant', () => {
        const wrapper = monterDialog();

        expect(wrapper.find('input[type="number"]').exists()).toBe(false);
        expect(wrapper.find('#montant').exists()).toBe(false);
    });

    it('exige un support et bloque l’envoi si son solde est insuffisant', async () => {
        const wrapper = monterDialog();
        expect(envoyer(wrapper).attributes('disabled')).toBeDefined();

        await wrapper
            .get('[data-testid="reglement-support"]')
            .setValue('s-caisse');

        expect(
            wrapper
                .find('[data-testid="reglement-solde-insuffisant"]')
                .exists(),
        ).toBe(true);
        expect(envoyer(wrapper).attributes('disabled')).toBeDefined();
    });

    it('envoie les encaissements et le support, jamais un montant', async () => {
        const wrapper = monterDialog();

        await wrapper
            .get('[data-testid="reglement-support"]')
            .setValue('s-orange');
        expect(envoyer(wrapper).attributes('disabled')).toBeUndefined();
        await envoyer(wrapper).trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, donnees] = vi.mocked(router.post).mock.calls[0];
        expect(url).toBe(
            '/backoffice/comptabilite/tresorerie/inter-agences/b/a/reglements',
        );
        expect(donnees).toEqual({
            encaissement_ids: ['e1', 'e2'],
            compte_tresorerie_origine_id: 's-orange',
            commentaire: null,
        });
        expect(donnees).not.toHaveProperty('montant');
    });

    it('explique quoi faire quand l’agence n’a aucun support utilisable', () => {
        const wrapper = monterDialog({ supports: [] });

        expect(
            wrapper.get('[data-testid="reglement-aucun-support"]').text(),
        ).toContain("versez d'abord leurs espèces à la caisse de l'agence");
    });
});

// ── Écran Inter-agences ──────────────────────────────────────────────────────

const monterIndex = (
    agences: InstanceType<typeof InterAgencesIndex>['$props']['agences'],
) =>
    shallowMount(InterAgencesIndex, {
        props: {
            agences,
            totaux: { a_verser: 750_000, a_recevoir: 300_000 },
            filters: { site_ids: [] },
            sites: [],
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Inter-agences — À verser / À recevoir', () => {
    it('affiche les totaux et, par agence, ce qu’elle doit verser et recevoir', () => {
        const wrapper = monterIndex([
            {
                site_id: 'b',
                site_nom: 'Kindia',
                a_verser: [
                    {
                        contrepartie_id: 'a',
                        contrepartie_nom: 'Matoto',
                        montant: 500_000,
                        en_cours_versement: 0,
                        nombre: 3,
                        detail_url: '/x/b/a',
                    },
                    {
                        contrepartie_id: 'c',
                        contrepartie_nom: 'Labé',
                        montant: 250_000,
                        en_cours_versement: 0,
                        nombre: 1,
                        detail_url: '/x/b/c',
                    },
                ],
                total_a_verser: 750_000,
                a_recevoir: [
                    {
                        contrepartie_id: 'd',
                        contrepartie_nom: 'Boké',
                        montant: 300_000,
                        en_cours_versement: 100_000,
                        detail_url: '/x/d/b',
                    },
                ],
                total_a_recevoir: 300_000,
            },
        ]);

        expect(wrapper.get('[data-testid="total-a-verser"]').text()).toBe(
            '750 000 GNF',
        );
        expect(wrapper.get('[data-testid="total-a-recevoir"]').text()).toBe(
            '300 000 GNF',
        );

        const carte = wrapper.get('[data-testid="agence-inter-agences"]');
        expect(carte.text()).toContain('Kindia');
        expect(carte.get('[data-testid="agence-total-a-verser"]').text()).toBe(
            '750 000 GNF',
        );
        expect(carte.text()).toContain('Matoto');
        expect(carte.text()).toContain('Labé');
        expect(carte.text()).toContain(
            'dont 100 000 GNF en cours de versement',
        );
        expect(
            carte.findAll('[href]').map((a) => a.attributes('href')),
        ).toEqual(['/x/b/a', '/x/b/c', '/x/d/b']);
    });

    it('indique quand rien n’est à verser ni à recevoir', () => {
        const wrapper = monterIndex([]);

        expect(wrapper.text()).toContain(
            'Aucun montant à verser ni à recevoir entre agences.',
        );
    });
});
