import TransmissionsPaieIndex from '@/pages/TransmissionsPaie/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, inject, provide } from 'vue';

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: () => true }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    router: { get: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: {},
        processing: false,
    }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

// Rend le slot #body de chaque colonne pour chaque ligne, comme le DataTable.
const Ligne = defineComponent({
    props: ['ligne'],
    setup(props) {
        provide('ligne', props.ligne);
    },
    template: '<div><slot /></div>',
});
const DataTable = defineComponent({
    components: { Ligne },
    props: ['value'],
    template:
        '<div><Ligne v-for="l in value" :key="l.id" :ligne="l"><slot /></Ligne></div>',
});
const Column = defineComponent({
    props: ['header', 'bodyClass', 'bodyStyle'],
    setup() {
        return { ligne: inject('ligne') };
    },
    template:
        '<div :data-colonne="header" :class="bodyClass" :style="bodyStyle"><slot name="body" :data="ligne" /></div>',
});
const InfoTooltip = defineComponent({
    props: ['label'],
    template: '<aside data-testid="info"><slot /></aside>',
});

const EXPLICATION =
    'Chaque mois, RH prépare les éléments de rémunération (salaire de base des contrats, primes et avantages validés), les contrôle, les valide — la version est alors figée — puis les transmet à la Comptabilité. Une correction ouvre une nouvelle version ; les précédentes restent consultables.';

const transmission = (surcharge: Record<string, unknown> = {}) => ({
    id: '01JTRANSMISSION',
    annee: 2026,
    mois: 10,
    libelle_mois: 'Octobre 2026',
    version: 2,
    statut: 'transmise',
    statut_label: 'Validée et figée par les RH',
    motif: null,
    nb_salaries: 5,
    nb_bloquantes: 0,
    nb_avertissements: 0,
    total_verse: 3950013,
    total_valorise: 0,
    preparee_par: 'Issa BARRY',
    preparee_le: '01/11/2026 20:45',
    validee_par: null,
    validee_le: null,
    transmise_par: null,
    transmise_le: null,
    statut_comptabilite: null,
    ...surcharge,
});

const monter = (surcharge: Record<string, unknown> = {}) =>
    shallowMount(TransmissionsPaieIndex, {
        props: {
            transmissions: [transmission(surcharge)],
            filters: { annee: '2026' },
            annees: [],
            mois_propose: { annee: 2026, mois: 11 },
            peut_agir_toutes_agences: true,
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: { DataTable, Column, Ligne, InfoTooltip },
        },
    });

const cellule = (wrapper: ReturnType<typeof monter>, colonne: string) =>
    wrapper.find(`[data-colonne="${colonne}"]`);

const normaliser = (texte: string) => texte.replace(/\s+/g, ' ').trim();

describe('Transmission de paie — liste', () => {
    it('garde le texte explicatif intact, dans l’info-bulle du titre', () => {
        const wrapper = monter();
        const info = wrapper.find('[data-testid="info"]');

        expect(normaliser(info.text())).toBe(EXPLICATION);

        // L'info-bulle est le seul endroit de la page où figure ce texte.
        expect(wrapper.findAll('p').map((p) => p.text())).not.toContainEqual(
            expect.stringContaining('Chaque mois, RH'),
        );
    });

    it.each([
        [3950013, '3 950 013 GNF'],
        [2248400, '2 248 400 GNF'],
        [250000, '250 000 GNF'],
        [100000000, '100 000 000 GNF'],
        [0, '0 GNF'],
    ])('affiche %d avec des espaces normales : « %s »', (montant, attendu) => {
        const wrapper = monter({ total_verse: montant });

        // toBe en égalité stricte : une espace fine (U+202F, quasi invisible) ferait échouer le test.
        expect(cellule(wrapper, 'Total versé').text()).toBe(attendu);
    });

    it('distingue un montant masqué (—) d’un montant nul (0 GNF)', () => {
        const wrapper = monter({ total_verse: null, total_valorise: 0 });

        expect(cellule(wrapper, 'Total versé').text()).toBe('—');
        expect(cellule(wrapper, 'Avantages valorisés').text()).toBe('0 GNF');
    });

    it('aligne les montants à droite, sans retour à la ligne', () => {
        const wrapper = monter();

        for (const colonne of ['Total versé', 'Avantages valorisés']) {
            const montant = cellule(wrapper, colonne);

            expect(montant.attributes('style')).toContain('text-align: right');
            expect(montant.classes()).toContain('whitespace-nowrap');
        }
    });

    it('conserve l’affichage de la version de chaque ligne', () => {
        const wrapper = monter({ version: 3 });

        expect(cellule(wrapper, 'Mois').text()).toContain('Version 3');
    });
});
