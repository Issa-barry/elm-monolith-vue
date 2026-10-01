import UsersShow from '@/pages/Users/Show.vue';
import type { AgentFiche, AgentSituationData } from '@/types/agent-fiche';
import type { SituationPeriode } from '@/types/situation';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const etat = vi.hoisted(() => ({ url: '/backoffice/users/agent-1' }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), replace: vi.fn() },
    usePage: () => ({
        component: 'Users/Show',
        get url() {
            return etat.url;
        },
    }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const stubs = {
    DetailHeader: {
        props: ['title', 'statusLabel'],
        template:
            '<header>{{ title }} {{ statusLabel }}<slot name="subtitle" /><slot name="actions" /></header>',
    },
    AgentSituationTab: {
        props: ['lienRapport'],
        template: '<div data-testid="agent-situation-panel" />',
    },
    AgentDepensesTab: {
        template: '<div data-testid="agent-depenses-panel" />',
    },
    StatusDot: {
        props: ['status', 'label'],
        template: '<span>{{ label }}</span>',
    },
};

const user: AgentFiche = {
    id: 'agent-1',
    prenom: 'Ousmane',
    nom: 'Sidibé',
    nom_complet: 'Ousmane Sidibé',
    matricule: '580273',
    email: 'gef@gmail.com',
    telephone: '+224666177006',
    code_phone_pays: '+224',
    code_pays: 'GN',
    pays: 'Guinée',
    ville: null,
    adresse: null,
    role: 'manager',
    role_label: 'Manager',
    is_active: true,
    is_pending_validation: false,
    sites: [{ id: 's1', nom: 'Usine de Cba', is_default: true }],
};

const periode: SituationPeriode = {
    cle: 'tout',
    date_debut: null,
    date_fin: null,
    options: [{ value: 'tout', label: 'Toute la période' }],
};

const situation: AgentSituationData = {
    ventes: {
        kpis: { ca_vendu: 0, encaisse: 0, reste_du: 0, nb_ventes: 0 },
        produits: [],
        paiements: { total_montant: 0, total_ventes: 0, repartition: [] },
    },
    encaissements: { montant: 0, nombre: 0, par_moyen: [] },
};

function monter(props: Record<string, unknown> = {}, url = etat.url) {
    etat.url = url;

    return mount(UsersShow, {
        props: {
            user,
            is_me: false,
            peut_modifier: true,
            situation,
            situation_periode: periode,
            lien_rapport: null,
            depenses: {
                resume: {
                    validees: { montant: 0, nombre: 0 },
                    en_attente: { montant: 0, nombre: 0 },
                    nombre: 4,
                },
                lignes: [],
                total_lignes: 4,
            },
            ...props,
        },
        global: { stubs },
    });
}

describe('Fiche agent', () => {
    it('affiche une fiche de consultation, sans champ éditable', () => {
        const wrapper = monter();

        expect(
            wrapper.find('[data-testid="agent-informations-panel"]').exists(),
        ).toBe(true);
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('[data-testid="agent-name"]').text()).toBe(
            'Ousmane Sidibé',
        );
        expect(wrapper.find('[data-testid="agent-role"]').text()).toBe(
            'Manager',
        );
        expect(
            wrapper
                .find('[data-testid="agent-edit-button"]')
                .attributes('href'),
        ).toBe('/backoffice/users/agent-1/edit');
    });

    it('propose Informations, Situation et Dépenses, jamais Mot de passe', () => {
        const wrapper = monter();

        for (const onglet of ['informations', 'situation', 'depenses']) {
            expect(
                wrapper.find(`[data-testid="agent-${onglet}-tab"]`).exists(),
            ).toBe(true);
        }
        expect(
            wrapper.find('[data-testid="agent-depenses-tab"]').text(),
        ).toContain('4');
        // Le mot de passe reste sous le seul contrôle de son titulaire (ADR 0015).
        expect(
            wrapper.find('[data-testid="agent-mot-de-passe-tab"]').exists(),
        ).toBe(false);
        expect(wrapper.find('input[type="password"]').exists()).toBe(false);
    });

    it('affiche le matricule dans les informations', () => {
        expect(monter().find('[data-testid="agent-matricule"]').text()).toBe(
            '580273',
        );
        expect(
            monter({ user: { ...user, matricule: null } })
                .find('[data-testid="agent-matricule"]')
                .text(),
        ).toBe('Non attribué');
    });

    it('masque Modifier, Situation et Dépenses sans les droits', () => {
        const wrapper = monter({
            peut_modifier: false,
            situation: null,
            depenses: null,
        });

        expect(wrapper.find('[data-testid="agent-edit-button"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-testid="agent-mot-de-passe-tab"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="agent-situation-tab"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="agent-depenses-tab"]').exists(),
        ).toBe(false);
    });

    it("ouvre l'onglet porté par l'URL", () => {
        const wrapper = monter({}, '/backoffice/users/agent-1?tab=situation');

        expect(
            wrapper.find('[data-testid="agent-situation-panel"]').exists(),
        ).toBe(true);
    });

    it("retombe sur Informations si l'onglet de l'URL n'est pas autorisé", () => {
        const wrapper = monter(
            { situation: null },
            '/backoffice/users/agent-1?tab=situation',
        );

        expect(
            wrapper.find('[data-testid="agent-situation-panel"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="agent-informations-panel"]').exists(),
        ).toBe(true);
    });
});
