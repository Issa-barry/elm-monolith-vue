import DataFilters from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
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
            name: 'InertiaLink',
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

const REVERSEMENTS = [
    {
        debiteur: { id: 'b', nom: 'Cba' },
        creancier: { id: 'a', nom: 'Matoto' },
        a_verser: 800_000,
        en_cours_versement: 0,
        verse: 0,
        statut: 'a_verser',
        statut_label: 'À envoyer',
        detail_url: '/x/b/a',
    },
];

const monterIndex = (reversements = REVERSEMENTS, siteIds: string[] = []) =>
    shallowMount(InterAgencesIndex, {
        props: {
            reversements,
            filters: { site_ids: siteIds },
            sites: [{ value: 'b', label: 'Cba' }],
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: { InertiaLink: false, ListPageActions: false },
        },
    });

describe('Inter-agences — reversements lisibles', () => {
    it('affiche qui verse, qui reçoit et le montant une seule fois avec un lien explicite', () => {
        const wrapper = monterIndex();
        const lignes = wrapper.findAll('[data-testid="reversement"]');
        expect(lignes).toHaveLength(1);
        expect(lignes[0].get('[data-testid="agence-verse"]').text()).toBe(
            'Cba',
        );
        expect(lignes[0].get('[data-testid="agence-recoit"]').text()).toBe(
            'Matoto',
        );
        expect(lignes[0].get('[data-testid="reste-a-verser"]').text()).toBe(
            '800 000 GNF',
        );
        expect(wrapper.text().match(/800 000 GNF/g)).toHaveLength(1);
        expect(wrapper.get('[data-testid="deja-verse"]').text()).toBe('0 GNF');
        expect(wrapper.getComponent(StatusDot).props()).toMatchObject({
            status: 'a_verser',
            label: 'À envoyer',
        });
        expect(lignes[0].get('a').attributes('href')).toBe('/x/b/a');
        expect(lignes[0].get('a').attributes('aria-label')).toContain(
            'de Cba vers Matoto',
        );
        expect(lignes[0].get('a').text()).toContain('Voir le détail');
        expect(
            wrapper.find('[data-testid="en-cours-versement"]').exists(),
        ).toBe(false);
    });

    it('distingue l’argent encore à verser de l’argent déjà envoyé', () => {
        const wrapper = monterIndex([
            {
                ...REVERSEMENTS[0],
                a_verser: 200_000,
                en_cours_versement: 600_000,
            },
        ]);
        expect(wrapper.get('[data-testid="reste-a-verser"]').text()).toBe(
            '200 000 GNF',
        );
        expect(wrapper.get('[data-testid="en-cours-versement"]').text()).toBe(
            '600 000 GNF',
        );
        expect(wrapper.text()).toContain('confirmer sa réception');
    });

    it('garde visible un reversement entièrement envoyé mais pas encore reçu', () => {
        const wrapper = monterIndex([
            { ...REVERSEMENTS[0], a_verser: 0, en_cours_versement: 800_000 },
        ]);
        expect(wrapper.findAll('[data-testid="reversement"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="reste-a-verser"]').text()).toBe(
            '0 GNF',
        );
        expect(wrapper.get('[data-testid="en-cours-versement"]').text()).toBe(
            '800 000 GNF',
        );
        expect(wrapper.find('[data-testid="reversements-vide"]').exists()).toBe(
            false,
        );
    });

    it('place le filtre Agence dans les actions d’en-tête et conserve les identifiants', () => {
        const wrapper = monterIndex(REVERSEMENTS, ['b']);
        const filtres = wrapper.getComponent(DataFilters);
        expect(
            wrapper
                .get('[data-testid="list-page-actions"]')
                .find('data-filters-stub')
                .exists(),
        ).toBe(true);
        expect(filtres.props('triggerOnly')).toBe(true);
        expect(filtres.props('values')).toEqual({ site_ids: ['b'] });
        expect(filtres.props('sites')).toEqual([{ id: 'b', nom: 'Cba' }]);
        expect(filtres.props('url')).toBe(
            '/backoffice/comptabilite/tresorerie/inter-agences',
        );
    });

    it('explique l’absence de reversement sans afficher de tableau vide', () => {
        const wrapper = monterIndex([]);
        expect(
            wrapper.get('[data-testid="reversements-vide"]').text(),
        ).toContain('Aucun reversement entre agences');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('précise que le résultat vide concerne les agences sélectionnées', () => {
        const wrapper = monterIndex([], ['b']);
        expect(
            wrapper.get('[data-testid="reversements-vide"]').text(),
        ).toContain('pour les agences sélectionnées');
    });

    it('affiche le montant reçu et le statut fournis par le serveur, même quand tout est versé', () => {
        const wrapper = monterIndex([
            {
                ...REVERSEMENTS[0],
                a_verser: 0,
                verse: 800_000,
                statut: 'verse',
                statut_label: 'Versé',
            },
        ]);
        expect(wrapper.get('[data-testid="deja-verse"]').text()).toBe(
            '800 000 GNF',
        );
        expect(wrapper.getComponent(StatusDot).props()).toMatchObject({
            status: 'verse',
            label: 'Versé',
        });
        expect(wrapper.find('[data-testid="reversements-vide"]').exists()).toBe(
            false,
        );
    });
});
