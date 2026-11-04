import TransmissionsPaieShow from '@/pages/TransmissionsPaie/Show.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: () => true }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: {},
        processing: false,
    }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const ligne = {
    id: 'ligne-1',
    origine: 'contrat' as const,
    code: null,
    libelle: 'Salaire de base',
    categorie_label: 'Salaire',
    versement: 'verse' as const,
    du: '01/10/2026',
    au: '31/10/2026',
    jours: 31,
    jours_mois: 31,
    montant_reference: 2500000,
    base_calcul: null,
    taux: null,
    montant: 2248400,
    detail_calcul: 'Mois entier',
    source: 'Contrat',
};

const monter = () =>
    shallowMount(TransmissionsPaieShow, {
        props: {
            transmission: {
                id: '01JTRANSMISSION',
                annee: 2026,
                mois: 10,
                libelle_mois: 'Octobre 2026',
                version: 2,
                statut: 'transmise',
                statut_label: 'Validée et figée par les RH',
                motif: null,
                nb_salaries: 1,
                nb_bloquantes: 0,
                nb_avertissements: 0,
                preparee_par: null,
                preparee_le: null,
                validee_par: null,
                validee_le: null,
                transmise_par: null,
                transmise_le: null,
                prise_en_charge_le: null,
                prise_en_charge_reference: null,
                statut_comptabilite: null,
                version_precedente: 1,
                est_figee: true,
                integre: true,
                empreinte: 'abc',
                organisation_complete: true,
                total_verse_organisation: 3950013,
                total_valorise_organisation: 0,
                a_jour: null,
                peut_annuler_validation: false,
            },
            salaries: [
                {
                    employe_id: '01JEMPLOYE',
                    salarie: 'Moussa SIDIBE',
                    matricule: '751450',
                    site: 'Matoto',
                    fonction: null,
                    total_verse: 3950013,
                    total_valorise: 250000,
                    lignes: [ligne],
                },
            ],
            recapitulatif: [
                {
                    cle: 'salaire',
                    element: 'Salaire de base',
                    code: null,
                    categorie_label: 'Salaire',
                    versement: 'verse',
                    nb_salaries: 1,
                    total: 100000000,
                },
            ],
            totaux_visibles: { verse: 3950013, valorise: 0, nb_salaries: 1 },
            anomalies: [],
            versions: [],
            ecarts_precedente: [
                {
                    salarie: 'Moussa SIDIBE',
                    element: 'Prime',
                    avant: null,
                    apres: 250000,
                    ecart: -1702300,
                },
            ],
            changements_depuis: [],
            filters: {},
            sites: [],
            peut_agir_toutes_agences: true,
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Transmission de paie — détail', () => {
    it('formate tous les montants avec des espaces normales visibles', () => {
        const texte = monter().text();

        for (const attendu of [
            '3 950 013 GNF',
            '2 500 000 GNF',
            '2 248 400 GNF',
            '100 000 000 GNF',
            '+ 250 000 GNF valorisés',
            '0 GNF',
        ]) {
            expect(texte).toContain(attendu);
        }
        // Aucune espace fine ou insécable ne subsiste dans un montant.
        expect(texte).not.toMatch(/\d[  ]\d/);
    });

    it('garde « — » pour un côté d’écart sans montant, et le signe d’un écart négatif', () => {
        const wrapper = monter();
        const cellules = wrapper
            .findAll('table')[0]
            .findAll('tbody td')
            .map((td) => td.text());

        expect(cellules).toEqual([
            'Moussa SIDIBE',
            'Prime',
            '—',
            '250 000 GNF',
            '-1 702 300 GNF',
        ]);
    });
});
