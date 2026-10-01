import AgentDepensesTab from '@/pages/Users/partials/AgentDepensesTab.vue';
import AgentSituationTab from '@/pages/Users/partials/AgentSituationTab.vue';
import type {
    AgentDepensesData,
    AgentSituationData,
} from '@/types/agent-fiche';
import type { SituationPeriode } from '@/types/situation';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn() },
    usePage: () => ({ url: '/backoffice/users/agent-1?tab=situation' }),
}));

const stubs = {
    SituationEntete: {
        props: ['titre', 'url'],
        template: '<h2 data-testid="entete" :data-url="url">{{ titre }}</h2>',
    },
    ProduitsVendusChart: { template: '<div />' },
    PaiementsChart: { template: '<div />' },
    StatusDot: {
        props: ['status', 'label'],
        template: '<span>{{ label }}</span>',
    },
};

const periode: SituationPeriode = {
    cle: 'ce_mois',
    date_debut: '2026-09-01',
    date_fin: '2026-09-30',
    options: [],
};

const situation: AgentSituationData = {
    ventes: {
        kpis: {
            ca_vendu: 150000,
            encaisse: 120000,
            reste_du: 30000,
            nb_ventes: 2,
        },
        produits: [],
        paiements: { total_montant: 150000, total_ventes: 2, repartition: [] },
    },
    encaissements: {
        montant: 125000,
        nombre: 2,
        par_moyen: [
            {
                cle: 'especes',
                libelle: 'Espèces',
                nombre: 1,
                montant: 100000,
                pourcentage_montant: 80,
            },
            {
                cle: 'mobile_money:orange_money',
                libelle: 'Orange Money',
                nombre: 1,
                montant: 25000,
                pourcentage_montant: 20,
            },
        ],
    },
};

describe('Situation de l’agent', () => {
    it('sépare l’encaissé de ses ventes des encaissements qu’il a réalisés', () => {
        const wrapper = mount(AgentSituationTab, {
            props: {
                agentId: 'agent-1',
                periode,
                data: situation,
                lienRapport: '/backoffice/rapports/activite?agent_id=agent-1',
            },
            global: { stubs },
        });

        expect(
            wrapper.find('[data-testid="entete"]').attributes('data-url'),
        ).toBe('/backoffice/users/agent-1');
        expect(wrapper.text()).toContain('Encaissé sur ses ventes');
        expect(wrapper.text()).toContain('Encaissements réalisés');

        const moyens = wrapper.find(
            '[data-testid="agent-encaissements-moyens"]',
        );
        expect(moyens.text()).toContain('Espèces');
        expect(moyens.text()).toContain('Orange Money');
        expect(moyens.text()).toContain('80 %');
        expect(
            wrapper
                .find('[data-testid="agent-voir-rapport"]')
                .attributes('href'),
        ).toBe('/backoffice/rapports/activite?agent_id=agent-1');
    });

    it('n’affiche pas de lien de détail quand le serveur n’en fournit pas', () => {
        const wrapper = mount(AgentSituationTab, {
            props: {
                agentId: 'agent-1',
                periode,
                data: situation,
                lienRapport: null,
            },
            global: { stubs },
        });

        expect(
            wrapper.find('[data-testid="agent-voir-rapport"]').exists(),
        ).toBe(false);
    });
});

describe('Dépenses de l’agent', () => {
    const depenses: AgentDepensesData = {
        resume: {
            validees: { montant: 10000, nombre: 1 },
            en_attente: { montant: 5000, nombre: 1 },
            nombre: 2,
        },
        lignes: [
            {
                id: 'd1',
                date_depense: '2026-09-20',
                type: 'Carburant',
                categorie: 'Interne',
                montant: 10000,
                statut: 'valide',
                statut_label: 'Validé',
            },
            {
                id: 'd2',
                date_depense: '2026-09-21',
                type: 'Repas',
                categorie: 'Interne',
                montant: 5000,
                statut: 'soumis',
                statut_label: 'Soumis',
            },
        ],
        total_lignes: 2,
    };

    it('liste les dépenses avec date, type, catégorie, montant et statut', () => {
        const wrapper = mount(AgentDepensesTab, {
            props: { data: depenses },
            global: { stubs },
        });

        const texte = wrapper.text();
        expect(texte).toContain('20/09/2026');
        expect(texte).toContain('Carburant');
        expect(texte).toContain('Validé');
        expect(texte).toContain('Soumis');
        expect(wrapper.find('a[href="/backoffice/depenses/d1"]').exists()).toBe(
            true,
        );
    });

    it('affiche un état vide sans dépense', () => {
        const wrapper = mount(AgentDepensesTab, {
            props: {
                data: {
                    ...depenses,
                    lignes: [],
                    total_lignes: 0,
                    resume: { ...depenses.resume, nombre: 0 },
                },
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain(
            'Aucune dépense saisie par cet agent.',
        );
    });
});
