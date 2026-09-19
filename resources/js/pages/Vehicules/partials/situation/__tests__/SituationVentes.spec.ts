import PaiementsChart from '@/pages/Vehicules/partials/situation/PaiementsChart.vue';
import ProduitsVendusChart from '@/pages/Vehicules/partials/situation/ProduitsVendusChart.vue';
import SituationVentesSection from '@/pages/Vehicules/partials/situation/SituationVentesSection.vue';
import type {
    SituationPaiements,
    SituationProduitVendu,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { mount } from '@vue/test-utils';
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
            pourcentage_ventes: 33.3,
            reste_a_encaisser: 0,
        },
        {
            code: 'partiel',
            label: 'Partiel',
            montant: 6_000_000,
            pourcentage_montant: 30,
            nb_ventes: 1,
            pourcentage_ventes: 33.3,
            reste_a_encaisser: 4_000_000,
        },
        {
            code: 'du',
            label: 'Dû',
            montant: 4_000_000,
            pourcentage_montant: 20,
            nb_ventes: 1,
            pourcentage_ventes: 33.3,
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

const stubGraphique = { global: { stubs: { Chart: true } } };

describe('PaiementsChart', () => {
    it('distingue montant, % du montant, nombre de ventes et % des ventes pour chaque statut', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
            ...stubGraphique,
        });

        const entetes = wrapper.find('thead').text();
        expect(entetes).toContain('Montant');
        expect(entetes).toContain('Ventes');

        const lignes = wrapper.findAll('tbody tr');
        expect(lignes).toHaveLength(3);
        expect(lignes[0].text()).toContain('Payé');
        expect(lignes[0].text()).toContain('10 000 000');
        expect(lignes[0].text()).toContain('50 %');
        expect(lignes[0].text()).toContain('33,3 %');
        expect(lignes[2].text()).toContain('Dû');
        expect(lignes[2].text()).toContain('20 %');
    });

    it('précise la part non encaissée uniquement sur la ligne Partiel', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
            ...stubGraphique,
        });

        const lignes = wrapper.findAll('tbody tr');
        expect(lignes[1].text()).toContain('dont reste 4 000 000');
        expect(lignes[0].text()).not.toContain('dont reste');
        expect(lignes[2].text()).not.toContain('dont reste');
    });

    it('affiche le total des ventes et du montant', () => {
        const wrapper = mount(PaiementsChart, {
            props: { paiements },
            ...stubGraphique,
        });

        const total = wrapper.find('tfoot').text();
        expect(total).toContain('Total');
        expect(total).toContain('20 000 000');
        expect(total).toContain('100 %');
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
            ...stubGraphique,
        });

        expect(wrapper.text()).toContain('Aucune vente facturée');
        expect(wrapper.find('table').exists()).toBe(false);
    });
});

describe('ProduitsVendusChart', () => {
    it('expose chaque produit avec sa quantité et son montant dans un tableau accessible', () => {
        const wrapper = mount(ProduitsVendusChart, {
            props: { produits },
            ...stubGraphique,
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
            ...stubGraphique,
        });

        expect(wrapper.text()).toContain('1 430');
        expect(wrapper.text()).toContain('102 700 000 GNF');
    });

    it("affiche un état vide lorsqu'aucun produit n'est vendu", () => {
        const wrapper = mount(ProduitsVendusChart, {
            props: { produits: [] },
            ...stubGraphique,
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
        periode_debut: '2026-09-01',
        periode_fin: '2026-09-30',
    };

    const monter = (surcharge: Partial<SituationVentesData> = {}) =>
        mount(SituationVentesSection, {
            props: {
                vehiculeRecherche: 'RC-111-AA',
                data: { ...data, ...surcharge },
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

    it("n'ajoute aucune date quand la période est « Tout »", () => {
        etat.permissions = ['ventes.read'];

        const lien = monter({ periode_debut: null, periode_fin: null }).find(
            '[data-testid="situation-voir-ventes"]',
        );

        expect(lien.attributes('href')).toBe(
            '/backoffice/ventes?vehicule=RC-111-AA',
        );
    });
});
