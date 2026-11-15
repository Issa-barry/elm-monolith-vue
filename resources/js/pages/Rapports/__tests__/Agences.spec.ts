import KpiCard from '@/components/KpiCard.vue';
import StatusDot from '@/components/StatusDot.vue';
import Agences from '@/pages/Rapports/Agences.vue';
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
            props: { href: String },
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { ...attrs, href: props.href }, slots.default?.()),
        }),
        usePage: () => ({
            url: '/backoffice/rapports/agences?periode=hier',
            props: {},
        }),
    };
});

const agence = (over: Record<string, unknown> = {}) => ({
    site_id: 'cba',
    site_nom: 'Cba',
    est_tresorerie_principale: false,
    ventes_nombre: 2,
    ventes_montant: 1_000_000,
    encaisse_nombre: 2,
    encaisse_montant: 750_000,
    pour_autres_agences: 0,
    par_moyen: {
        especes: {
            cle: 'especes',
            libelle: 'Espèces',
            montant: 600_000,
        },
        'mobile_money:orange_money': {
            cle: 'mobile_money:orange_money',
            libelle: 'Orange Money',
            montant: 150_000,
        },
    },
    dettes_nombre: 1,
    dettes_montant: 250_000,
    charges_payees: 200_000,
    charges_payees_nombre: 2,
    charges_en_attente: 50_000,
    charges_en_attente_nombre: 1,
    charges_avances_tiers: 80_000,
    remise: {
        remis: 300_000,
        en_transit: 0,
        reste_a_remettre: 700_000,
        disponible: 700_000,
        derniere_remise: '2026-11-13',
        statut: 'partiellement_remis',
        statut_label: 'Partiellement remis',
    },
    ...over,
});

const monter = (peutVoirRemises = true) =>
    shallowMount(Agences, {
        props: {
            url: '/backoffice/rapports/agences',
            export_url: '/backoffice/rapports/agences/export',
            filters: {
                periode: 'hier',
                date_debut: null,
                date_fin: null,
                site_ids: [],
            },
            periode: {
                cle: 'hier',
                date_debut: '2026-11-12',
                date_fin: '2026-11-12',
                libelle: 'Hier (12/11/2026)',
                options: [],
            },
            sites: [],
            rapport: {
                agences: [
                    agence(),
                    agence({
                        site_id: 'matoto',
                        site_nom: 'Matoto',
                        est_tresorerie_principale: true,
                        par_moyen: [],
                        remise: {
                            remis: null,
                            en_transit: null,
                            reste_a_remettre: null,
                            disponible: null,
                            derniere_remise: null,
                            statut: 'tresorerie_principale',
                            statut_label: 'Trésorerie principale',
                        },
                    }),
                ],
                moyens: [
                    { cle: 'especes', libelle: 'Espèces' },
                    {
                        cle: 'mobile_money:orange_money',
                        libelle: 'Orange Money',
                    },
                ],
                totaux: {
                    ventes_nombre: 2,
                    ventes_montant: 1_000_000,
                    encaisse_nombre: 2,
                    encaisse_montant: 750_000,
                    pour_autres_agences: 0,
                    par_moyen: {
                        especes: 600_000,
                        'mobile_money:orange_money': 150_000,
                    },
                    dettes_nombre: 1,
                    dettes_montant: 250_000,
                    charges_payees: 400_000,
                    charges_payees_nombre: 4,
                    charges_en_attente: 100_000,
                    charges_en_attente_nombre: 2,
                    charges_avances_tiers: 160_000,
                    remis: 300_000,
                    en_transit: 0,
                    reste_a_remettre: 700_000,
                    reste_incomplet: false,
                    par_statut: { partiellement_remis: 1 },
                },
                central: { id: 'matoto', nom: 'Matoto' },
            },
            peut_voir_remises: peutVoirRemises,
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Rapports/Agences — rapport par agence', () => {
    it('distingue le remis de la période des montants à ce jour (en transit, reste)', () => {
        const wrapper = monter();

        expect(wrapper.get('[data-testid="entete-remis"]').text()).toContain(
            'sur la période',
        );
        expect(
            wrapper.get('[data-testid="entete-en-transit"]').text(),
        ).toContain('à ce jour');
        expect(wrapper.get('[data-testid="entete-reste"]').text()).toContain(
            'à ce jour',
        );
        const cartes = wrapper.findAllComponents(KpiCard);
        expect(
            cartes
                .find((c) => c.props('title') === 'Reste à remettre')
                ?.props('detail'),
        ).toBe('À ce jour · 0 en transit');
        expect(cartes.every((c) => c.props('compact'))).toBe(true);
        expect(cartes.map((c) => c.props('title'))).toContain('Charges payées');
        expect(
            cartes
                .find((c) => c.props('title') === 'Remis à Matoto')
                ?.props('detail'),
        ).toBe('Reçu sur la période');
    });

    it('affiche une colonne par moyen utilisé et le remis, le reste et le statut de remise', () => {
        const wrapper = monter();
        const tableau = wrapper.get('[data-testid="rapport-agences-tableau"]');

        expect(tableau.text()).toContain('Orange Money');
        expect(tableau.text()).toContain('Remis à Matoto');
        expect(
            wrapper.get('[data-testid="rapport-agence-remis"]').text(),
        ).toContain('300 000');
        expect(
            wrapper.get('[data-testid="rapport-agence-reste"]').text(),
        ).toContain('700 000');

        const statuts = wrapper.findAllComponents(StatusDot);
        expect(statuts[0].props('status')).toBe('partiellement_remis');
        expect(statuts[0].props('label')).toBe('Partiellement remis');
    });

    it('groupe les encaissements par moyen puis le total encaissé, en dernier', () => {
        const wrapper = monter();

        expect(wrapper.get('[data-testid="groupe-encaissements"]').text()).toBe(
            'Encaissements de la période',
        );
        expect(
            wrapper
                .findAll('[data-testid="entete-moyen"]')
                .map((th) => th.text()),
        ).toEqual(['Espèces', 'Orange Money']);

        const entetes = wrapper
            .findAll('[data-testid="rapport-agences-tableau"] thead tr')[1]
            .findAll('th')
            .map((th) => th.text());
        expect(entetes.indexOf('Total encaissé')).toBe(
            entetes.indexOf('Orange Money') + 1,
        );

        const cellules = wrapper
            .findAll('[data-testid="rapport-agence-ligne"]')[0]
            .findAll('td')
            .map((td) => td.text());
        const total = cellules.findIndex((c) => c.startsWith('750 000'));
        expect(cellules.slice(total - 2, total + 1)).toEqual([
            '600 000',
            '150 000',
            '750 000',
        ]);
        expect(
            wrapper
                .get('[data-testid="rapport-agences-total-encaisse"]')
                .text(),
        ).toBe('750 000');
        expect(
            wrapper.findAllComponents(KpiCard).map((c) => c.props('title')),
        ).toContain('Total encaissé');
    });

    it('affiche les charges payées, la part en attente et les avances sur tiers', () => {
        const charges = monter()
            .findAll('[data-testid="rapport-agence-charges"]')[0]
            .text();

        expect(charges).toContain('200 000');
        expect(charges).toContain('+ 50 000 en attente');
        expect(charges).toContain('dont 80 000 avances');
    });

    it('la trésorerie principale n’a ni remis ni reste, et pas de lien Remises', () => {
        const wrapper = monter();
        const lignes = wrapper.findAll('[data-testid="rapport-agence-ligne"]');

        expect(lignes[1].text()).toContain('Fonds déjà centralisés');
        expect(
            lignes[1].find('[data-testid="rapport-agence-remises"]').exists(),
        ).toBe(false);
        expect(
            lignes[0].find('[data-testid="rapport-agence-remises"]').exists(),
        ).toBe(true);
    });

    it('le détail des opérations ouvre le rapport d’activité de l’agence sur la même période', () => {
        const lien = monter()
            .findAll('[data-testid="rapport-agence-detail"]')[0]
            .attributes('href');

        expect(lien).toBe(
            '/backoffice/rapports/activite?periode=hier&site_ids%5B%5D=cba',
        );
    });

    it('sans accès à la trésorerie, aucun lien vers les remises', () => {
        expect(
            monter(false)
                .find('[data-testid="rapport-agence-remises"]')
                .exists(),
        ).toBe(false);
    });
});
