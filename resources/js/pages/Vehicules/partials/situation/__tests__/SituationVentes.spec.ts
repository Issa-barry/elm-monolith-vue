import PaiementsChart from '@/pages/Vehicules/partials/situation/PaiementsChart.vue';
import ProduitsVendusChart from '@/pages/Vehicules/partials/situation/ProduitsVendusChart.vue';
import SituationVentesSection from '@/pages/Vehicules/partials/situation/SituationVentesSection.vue';
import type {
    SituationPaiements,
    SituationPeriode,
    SituationProduitVendu,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { mount } from '@vue/test-utils';
import Chart from 'primevue/chart';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const etat = vi.hoisted(() => ({
    permissions: [] as string[],
    moduleFlags: {} as Record<string, boolean>,
}));

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({
        can: (permission: string) => etat.permissions.includes(permission),
    }),
}));

// Canvas indisponible sous jsdom : le graphique est remplacé par un composant qui déclare les
// mêmes props, pour pouvoir vérifier le type et les options réellement transmis.
vi.mock('primevue/chart', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'Chart',
            props: {
                type: { type: String, default: '' },
                data: { type: Object, default: () => ({}) },
                options: { type: Object, default: () => ({}) },
                plugins: { type: Array, default: () => [] },
                canvasProps: { type: Object, default: () => ({}) },
            },
            setup: () => () => h('div', { 'data-testid': 'graphique' }),
        }),
    };
});

vi.mock('@/composables/useChartTheme', async () => {
    const { ref } = await import('vue');

    return {
        useChartTheme: () => ({
            getPrimary: ref('blue'),
            getSurface: ref('slate'),
            isDarkTheme: ref(false),
        }),
    };
});

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        usePage: () => ({ props: { module_flags: etat.moduleFlags } }),
        router: { get: vi.fn() },
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

interface OptionsCamembert {
    plugins: {
        tooltip: {
            callbacks: {
                label: (ctx: { dataIndex: number }) => string;
                afterLabel: (ctx: { dataIndex: number }) => string[];
            };
        };
    };
}

const paiements: SituationPaiements = {
    total_montant: 20_000_000,
    total_ventes: 3,
    repartition: [
        {
            code: 'paye',
            label: 'Payé',
            montant: 10_000_000,
            pourcentage_montant: 50,
            nb_ventes: 1,
            reste_a_encaisser: 0,
        },
        {
            code: 'partiel',
            label: 'Partiel',
            montant: 6_000_000,
            pourcentage_montant: 30,
            nb_ventes: 1,
            reste_a_encaisser: 4_000_000,
        },
        {
            code: 'impaye',
            label: 'Impayé',
            montant: 4_000_000,
            pourcentage_montant: 20,
            nb_ventes: 1,
            reste_a_encaisser: 4_000_000,
        },
    ],
};

const produits: SituationProduitVendu[] = [
    {
        variante_id: 'v1',
        libelle: 'Bouteille 1500ml',
        quantite: 1250,
        montant: 93_700_000,
    },
    {
        variante_id: 'v2',
        libelle: 'Pack 25 sachets',
        quantite: 180,
        montant: 9_000_000,
    },
];

describe('PaiementsChart', () => {
    it('présente statut, montant, un seul pourcentage (celui du montant) et nombre de ventes', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
        });

        const colonnes = wrapper
            .findAll('thead th')
            .map((entete) => entete.text());
        expect(colonnes).toEqual([
            'Statut',
            'Montant',
            '%',
            'Nombre de ventes',
        ]);

        const lignes = wrapper.findAll('tbody tr');
        expect(lignes).toHaveLength(3);
        expect(lignes[0].text()).toContain('Payé');
        expect(lignes[0].text()).toContain('10 000 000 GNF');
        expect(lignes[0].text()).toContain('50 %');
        expect(lignes[2].text()).toContain('Impayé');
        expect(lignes[2].text()).toContain('20 %');
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(4);
    });

    it('précise la part non encaissée uniquement sur la ligne Partiel', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
        });

        const lignes = wrapper.findAll('tbody tr');
        expect(lignes[1].text()).toContain('dont reste à payer 4 000 000 GNF');
        expect(lignes[0].text()).not.toContain('dont reste');
        expect(lignes[2].text()).not.toContain('dont reste');
    });

    it('affiche le total des ventes et du montant', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
        });

        const total = wrapper.find('tfoot').text();
        expect(total).toContain('Total');
        expect(total).toContain('20 000 000 GNF');
        expect(total).toContain('100 %');
        expect(total).toContain('3');
    });

    it("utilise le camembert plein d'Apollo, sans texte superposé au graphique", () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
        });

        expect(wrapper.findComponent(Chart).props('type')).toBe('pie');
        expect(wrapper.find('.pointer-events-none').exists()).toBe(false);
    });

    it('compose une infobulle qui ne répète pas le statut déjà en titre', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
        });

        const options = wrapper
            .findComponent(Chart)
            .props('options') as unknown as OptionsCamembert;
        const { callbacks } = options.plugins.tooltip;

        expect(callbacks.label({ dataIndex: 2 })).toBe(' 4 000 000 GNF');
        expect(callbacks.afterLabel({ dataIndex: 2 })).toEqual([
            ' 20 % du montant',
            ' 1 vente',
        ]);
    });

    it("affiche un état vide lorsqu'aucune vente n'est facturée", () => {
        const wrapper = mount(PaiementsChart, {
            props: {
                paiements: {
                    ...paiements,
                    total_montant: 0,
                    total_ventes: 0,
                },
            },
        });

        expect(wrapper.text()).toContain('Aucune vente facturée');
        expect(wrapper.find('table').exists()).toBe(false);
    });
});

describe('ProduitsVendusChart', () => {
    it('expose chaque produit avec sa quantité et son montant dans un tableau accessible', () => {
        const wrapper = mount(ProduitsVendusChart, {
            props: { produits },
        });

        const lignes = wrapper.findAll('tbody tr');
        expect(lignes).toHaveLength(2);
        expect(lignes[0].text()).toContain('Bouteille 1500ml');
        expect(lignes[0].text()).toContain('1 250');
        expect(lignes[0].text()).toContain('93 700 000 GNF');
        expect(lignes[1].text()).toContain('Pack 25 sachets');
    });

    it('résume la quantité totale vendue', () => {
        const wrapper = mount(ProduitsVendusChart, {
            props: { produits },
        });

        expect(wrapper.text()).toContain('1 430');
        expect(wrapper.text()).toContain('102 700 000 GNF');
    });

    it("affiche un état vide lorsqu'aucun produit n'est vendu", () => {
        const wrapper = mount(ProduitsVendusChart, {
            props: { produits: [] },
        });

        expect(wrapper.text()).toContain('Aucun produit vendu');
        expect(wrapper.find('table').exists()).toBe(false);
    });
});

describe('SituationVentesSection — lien « Voir les ventes »', () => {
    const data: SituationVentesData = {
        kpis: { ca_vendu: 0, encaisse: 0, reste_du: 0, nb_ventes: 0 },
        produits: [],
        paiements: { total_montant: 0, total_ventes: 0, repartition: [] },
    };

    const CE_MOIS: SituationPeriode = {
        cle: 'ce_mois',
        date_debut: '2026-09-01',
        date_fin: '2026-09-30',
        options: [],
    };

    const monter = (periode: SituationPeriode = CE_MOIS) =>
        mount(SituationVentesSection, {
            props: {
                vehiculeRecherche: 'RC-111-AA',
                periode,
                data,
            },
            global: {
                stubs: { ProduitsVendusChart: true, PaiementsChart: true },
            },
        });

    beforeEach(() => {
        etat.permissions = [];
        etat.moduleFlags = {};
    });

    it('reste masqué sans la permission ventes.read', () => {
        const wrapper = monter();

        expect(
            wrapper.find('[data-testid="situation-voir-ventes"]').exists(),
        ).toBe(false);
    });

    it('reste masqué quand le module Ventes est désactivé', () => {
        etat.permissions = ['ventes.read'];
        etat.moduleFlags = { ventes: false };

        expect(
            monter().find('[data-testid="situation-voir-ventes"]').exists(),
        ).toBe(false);
    });

    it('renvoie vers Ventes filtré sur le véhicule et la période affichée', () => {
        etat.permissions = ['ventes.read'];

        const lien = monter().find('[data-testid="situation-voir-ventes"]');

        expect(lien.attributes('href')).toBe(
            '/backoffice/ventes?vehicule=RC-111-AA&date_debut=2026-09-01&date_fin=2026-09-30',
        );
    });

    it("n'ajoute aucune date quand la période est « Toute la période »", () => {
        etat.permissions = ['ventes.read'];

        const lien = monter({
            cle: 'tout',
            date_debut: null,
            date_fin: null,
            options: [],
        }).find('[data-testid="situation-voir-ventes"]');

        expect(lien.attributes('href')).toBe(
            '/backoffice/ventes?vehicule=RC-111-AA',
        );
    });

    it('reprend les bornes d’une période personnalisée', () => {
        etat.permissions = ['ventes.read'];

        const lien = monter({
            cle: 'personnalisee',
            date_debut: '2026-09-01',
            date_fin: '2026-09-13',
            options: [],
        }).find('[data-testid="situation-voir-ventes"]');

        expect(lien.attributes('href')).toBe(
            '/backoffice/ventes?vehicule=RC-111-AA&date_debut=2026-09-01&date_fin=2026-09-13',
        );
    });
});
