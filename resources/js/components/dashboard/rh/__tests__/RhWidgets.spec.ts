import RhAgencesWidget from '@/components/dashboard/rh/RhAgencesWidget.vue';
import RhCartesWidget from '@/components/dashboard/rh/RhCartesWidget.vue';
import RhDossiersWidget from '@/components/dashboard/rh/RhDossiersWidget.vue';
import RhFinsCddWidget from '@/components/dashboard/rh/RhFinsCddWidget.vue';
import RhMotifsWidget from '@/components/dashboard/rh/RhMotifsWidget.vue';
import { nombreTexte, pluriel } from '@/components/dashboard/rh/nombres';
import type { AbsencesRh, FinCddRh } from '@/types/dashboard-rh';
import { mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { href: props.href, ...attrs }, slots.default?.()),
        }),
    };
});

const texte = (s: string) => s.replace(/\s+/g, ' ').trim();
const cellules = (ligne: ReturnType<ReturnType<typeof mount>['find']>) =>
    ligne.findAll('td').map((td) => texte(td.text()));
const global = { plugins: [PrimeVue] };

const absences = (champs: Partial<AbsencesRh> = {}): AbsencesRh => ({
    jours: 8,
    absences: 3,
    salaries: 2,
    taux: (8 / 52) * 100,
    taux_motif: null,
    jours_attendus: 52,
    salaries_taux: 2,
    salaries_exclus: 1,
    injustifiees_jours: 2,
    injustifiees_absences: 1,
    absents_aujourdhui: 1,
    a_venir: 1,
    jusqu_au: '2026-11-26',
    par_motif: [],
    ...champs,
});

const fin = (champs: Partial<FinCddRh> = {}): FinCddRh => ({
    contrat_id: 'c1',
    employe_id: 'e1',
    salarie: 'Alpha DIALLO',
    matricule: '000123',
    site: 'Matoto',
    date_fin: '2026-11-20',
    jours: -6,
    statut: 'echeance_depassee',
    statut_label: 'Fin dépassée',
    ...champs,
});

const valeur = (wrapper: ReturnType<typeof mount>, id: string) =>
    texte(
        wrapper.find(`[data-testid="kpi-${id}"] [data-slot="valeur"]`).text(),
    );
const detail = (wrapper: ReturnType<typeof mount>, id: string) =>
    texte(
        wrapper.find(`[data-testid="kpi-${id}"] [data-slot="detail"]`).text(),
    );

describe('nombres', () => {
    it('formate des entiers français et accorde le pluriel', () => {
        expect(nombreTexte(1250)).toBe('1 250');
        expect(pluriel(0, 'salarié')).toBe('0 salarié');
        expect(pluriel(1, 'salarié')).toBe('1 salarié');
        expect(pluriel(3, 'salarié')).toBe('3 salariés');
    });
});

describe('RhCartesWidget', () => {
    const tout = {
        effectifs: { actifs: 39, suspendus: 2, entrees: 1, sorties: 0 },
        sansDateEmbauche: 39,
        contrats: { cdi: 4, cdd: 3, sans_contrat: 32 },
        finsCdd: [
            fin(),
            fin({ contrat_id: 'c2', statut: 'expire_bientot', jours: 14 }),
        ],
        absences: absences(),
    };

    it('affiche une ligne de quatre cartes par bloc consultable', () => {
        const wrapper = mount(RhCartesWidget, { props: tout });

        expect(
            wrapper.find('[data-testid="rh-ligne-effectifs"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-testid="rh-ligne-contrats"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-testid="rh-ligne-absences"]').exists()).toBe(
            true,
        );
        expect(wrapper.findAll('[data-testid^="kpi-"]')).toHaveLength(12);

        expect(valeur(wrapper, 'actifs')).toBe('39');
        expect(detail(wrapper, 'actifs')).toBe(
            '41 présents avec les suspendus',
        );
        expect(detail(wrapper, 'entrees')).toBe('39 sans date d’embauche');
        expect(valeur(wrapper, 'sans-contrat')).toBe('32');
        expect(valeur(wrapper, 'fins-cdd')).toBe('2');
        expect(
            wrapper
                .find('[data-testid="kpi-fins-cdd"] [data-slot="detail"]')
                .classes(),
        ).toContain('text-amber-600');
        expect(detail(wrapper, 'fins-cdd')).toBe(
            '1 dépassée · 1 sous 30 jours',
        );
        expect(valeur(wrapper, 'jours-absence')).toBe('8');
        expect(detail(wrapper, 'jours-absence')).toBe(
            '3 absences · 2 salariés',
        );
        // Une décimale, virgule française (lib/pourcentages).
        expect(valeur(wrapper, 'taux')).toBe('15,4 %');
        expect(detail(wrapper, 'taux')).toBe('2 pris en compte · 1 exclu');
        expect(
            wrapper
                .find('[data-testid="kpi-taux"] [title]')
                .attributes('title'),
        ).toContain('52 jours attendus');
        expect(valeur(wrapper, 'injustifiees')).toBe('2');
        expect(valeur(wrapper, 'absents')).toBe('1');
        expect(
            texte(wrapper.find('[data-testid="rh-ligne-absences"]').text()),
        ).toContain('jusqu’au 26/11/2026');
    });

    it('n’affiche que les blocs envoyés par le serveur', () => {
        const wrapper = mount(RhCartesWidget, {
            props: {
                effectifs: null,
                sansDateEmbauche: null,
                contrats: null,
                finsCdd: null,
                absences: absences(),
            },
        });

        expect(
            wrapper.find('[data-testid="rh-ligne-effectifs"]').exists(),
        ).toBe(false);
        expect(wrapper.find('[data-testid="rh-ligne-contrats"]').exists()).toBe(
            false,
        );
        expect(wrapper.findAll('[data-testid^="kpi-"]')).toHaveLength(4);
    });

    it('affiche un tiret quand la période ne permet pas de calculer le taux', () => {
        const wrapper = mount(RhCartesWidget, {
            props: {
                ...tout,
                absences: absences({
                    taux: null,
                    taux_motif: 'periode',
                    jours_attendus: null,
                    salaries_taux: null,
                    salaries_exclus: null,
                }),
            },
        });

        expect(valeur(wrapper, 'taux')).toBe('—');
        expect(detail(wrapper, 'taux')).toBe(
            'Période bornée et commencée requise',
        );
    });

    it('affiche un tiret, jamais 0 %, sans salarié à date d’embauche connue', () => {
        const wrapper = mount(RhCartesWidget, {
            props: {
                ...tout,
                absences: absences({
                    taux: null,
                    taux_motif: 'aucun_salarie',
                    jours_attendus: 0,
                    salaries_taux: 0,
                    salaries_exclus: 39,
                }),
            },
        });

        expect(valeur(wrapper, 'taux')).toBe('—');
        expect(detail(wrapper, 'taux')).toBe('0 pris en compte · 39 exclus');
    });

    it('n’affiche que le taux sans accès aux fiches salariés', () => {
        const masque = {
            jours_attendus: null,
            salaries_taux: null,
            salaries_exclus: null,
        };
        const wrapper = mount(RhCartesWidget, {
            props: { ...tout, absences: absences(masque) },
        });

        expect(valeur(wrapper, 'taux')).toBe('15,4 %');
        expect(detail(wrapper, 'taux')).toBe(
            'Salariés à date d’embauche connue',
        );
        expect(
            wrapper
                .find('[data-testid="kpi-taux"] [title]')
                .attributes('title'),
        ).not.toContain('jours attendus.');

        const sansSalarie = mount(RhCartesWidget, {
            props: {
                ...tout,
                absences: absences({
                    ...masque,
                    taux: null,
                    taux_motif: 'aucun_salarie',
                }),
            },
        });
        expect(detail(sansSalarie, 'taux')).toBe(
            'Aucune date d’embauche connue',
        );
    });
});

describe('RhAgencesWidget', () => {
    it('affiche l’effectif de chaque agence, salariés sans agence compris', () => {
        const wrapper = mount(RhAgencesWidget, {
            props: {
                lignes: [
                    {
                        site_id: 's1',
                        site: 'Matoto',
                        actifs: 1250,
                        suspendus: 1,
                        entrees: 2,
                        sorties: 0,
                    },
                    {
                        site_id: null,
                        site: 'Sans agence',
                        actifs: 1,
                        suspendus: 0,
                        entrees: 0,
                        sorties: 0,
                    },
                ],
            },
            global,
        });

        const lignes = wrapper.findAll('[data-testid="rh-agences"] tbody tr');
        expect(lignes).toHaveLength(2);
        expect(cellules(lignes[0])).toEqual(['Matoto', '1 250', '1', '2', '0']);
        expect(cellules(lignes[1])).toEqual([
            'Sans agence',
            '1',
            '0',
            '0',
            '0',
        ]);
    });
});

describe('RhDossiersWidget', () => {
    it('compte les dossiers à compléter', () => {
        const wrapper = mount(RhDossiersWidget, {
            props: {
                dossiers: {
                    presents: 39,
                    sans_date_embauche: 39,
                    sans_fonction: 2,
                    sans_service: 39,
                    sans_agence: 0,
                    sortis_sans_date: 1,
                },
            },
        });

        expect(texte(wrapper.text())).toContain('Sur 39 salariés présents');
        const ligne = (id: string) =>
            wrapper
                .find(`[data-testid="rh-dossiers-${id}"]`)
                .findAll('span')
                .map((s) => texte(s.text()));
        expect(ligne('fonction')).toEqual(['Sans fonction', '2']);
        expect(ligne('agence')).toEqual(['Sans agence', '0']);
        expect(ligne('sortis-sans-date')).toEqual([
            'Sortis sans date de sortie',
            '1',
        ]);
        expect(texte(wrapper.text())).toContain('exclu du taux d’absentéisme');
    });
});

describe('RhMotifsWidget', () => {
    it('donne la part de chaque motif dans les jours d’absence', () => {
        const wrapper = mount(RhMotifsWidget, {
            props: {
                lignes: [
                    {
                        motif_id: 'm1',
                        libelle: 'Maladie',
                        injustifiee: false,
                        absences: 2,
                        jours: 6,
                    },
                    {
                        motif_id: 'm2',
                        libelle: 'Absence injustifiée',
                        injustifiee: true,
                        absences: 1,
                        jours: 2,
                    },
                ],
            },
            global,
        });

        const lignes = wrapper.findAll('[data-testid="rh-motifs"] tbody tr');
        expect(cellules(lignes[0])).toEqual(['Maladie', '2', '6', '75,0 %']);
        expect(cellules(lignes[1])).toEqual([
            'Absence injustifiée',
            '1',
            '2',
            '25,0 %',
        ]);
    });

    it('signale l’absence de données', () => {
        const wrapper = mount(RhMotifsWidget, {
            props: { lignes: [] },
            global,
        });

        expect(wrapper.text()).toContain('Aucune absence sur la période');
    });
});

describe('RhFinsCddWidget', () => {
    it('affiche l’échéance en point coloré et le lien vers la fiche seulement s’il est permis', () => {
        const StatusDot = defineComponent({
            name: 'StatusDot',
            props: ['status', 'label'],
            setup: (props) => () =>
                h('span', { 'data-status': props.status }, props.label),
        });
        const wrapper = mount(RhFinsCddWidget, {
            props: {
                lignes: [
                    fin(),
                    fin({
                        contrat_id: 'c2',
                        employe_id: null,
                        salarie: 'Binta BAH',
                        statut: 'expire_bientot',
                        statut_label: 'Se termine dans 14 jours',
                        date_fin: '2026-12-10',
                    }),
                ],
            },
            global: { ...global, stubs: { StatusDot } },
        });

        const lignes = wrapper.findAll('[data-testid="rh-fins-cdd"] tbody tr');
        expect(lignes[0].find('[data-status]').attributes('data-status')).toBe(
            'echeance_depassee',
        );
        expect(
            lignes[0].find('[data-testid="fin-cdd-fiche"]').attributes('href'),
        ).toBe('/backoffice/employes/e1');
        expect(texte(lignes[0].text())).toContain('20/11/2026');
        expect(lignes[1].find('[data-testid="fin-cdd-fiche"]').exists()).toBe(
            false,
        );
        expect(texte(lignes[1].text())).toContain('Binta BAH');
        expect(texte(lignes[1].text())).toContain('Se termine dans 14 jours');
    });
});
