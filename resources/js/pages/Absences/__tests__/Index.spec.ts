import AbsencesIndex from '@/pages/Absences/Index.vue';
import type { AbsenceRow } from '@/types/absence';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, inject, provide } from 'vue';

const droits = vi.hoisted(() => ({ create: true }));

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({
        can: (p: string) => (p === 'rh-absences.create' ? droits.create : true),
    }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
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
    props: ['header'],
    setup() {
        return { ligne: inject('ligne') };
    },
    template:
        '<div :data-colonne="header"><slot name="body" :data="ligne" /></div>',
});

// Rend les slots nommés de l'en-tête (Exporter → Importer → Filtres → Nouveau).
const ListPageActions = defineComponent({
    template:
        '<div><slot name="export" /><slot name="import" /><slot name="filters" /><slot name="primary" /></div>',
});

const ligne = (surcharge: Partial<AbsenceRow> = {}): AbsenceRow => ({
    id: 'a1',
    employe_id: 'e1',
    salarie: 'Alpha DIALLO',
    matricule: '001',
    agence: 'Matoto',
    motif_id: 'm1',
    motif: 'Maladie',
    date_debut: '2026-11-10',
    date_fin: '2026-11-12',
    date_debut_label: '10/11/2026',
    date_fin_label: '12/11/2026',
    duree_jours: 3,
    commentaire: null,
    a_justificatif: false,
    justificatif_nom: null,
    statut: 'enregistree',
    etat: 'en_cours',
    etat_label: 'En cours',
    auteur: 'Issa BARRY',
    saisie_le: '10/11/2026 08:00',
    peut_modifier: true,
    peut_annuler: false,
    ...surcharge,
});

const monter = (surcharge: Record<string, unknown> = {}) =>
    shallowMount(AbsencesIndex, {
        props: {
            absences: {
                data: [ligne()],
                links: [],
                current_page: 1,
                last_page: 1,
                total: 1,
            },
            filters: {},
            sites: [{ id: 's1', nom: 'Matoto' }],
            motifs: [{ value: 'm1', label: 'Maladie' }],
            auteurs: [],
            statuts: [{ value: 'annulee', label: 'Annulée' }],
            motifs_saisissables: [{ value: 'm1', label: 'Maladie' }],
            salaries: [{ value: 'e1', label: 'Alpha DIALLO (001)' }],
            ...surcharge,
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: { DataTable, Column, Ligne, ListPageActions },
        },
    });

describe('Liste des absences', () => {
    it('affiche les colonnes attendues, durée et statut compris', () => {
        const w = monter();
        const colonnes = w
            .findAll('[data-colonne]')
            .map((c) => c.attributes('data-colonne'));
        for (const c of [
            'Salarié',
            'Matricule',
            'Agence',
            'Motif',
            'Début',
            'Fin',
            'Durée',
            'Statut',
            'Commentaire',
            'Saisie',
        ])
            expect(colonnes).toContain(c);
        expect(w.text()).toContain('3 jours');
        expect(w.text()).toContain('1 absence');
    });

    it('filtre par le serveur dans l’ordre A2 : Statut → Salarié → Période → Motif → Auteur', () => {
        const filtres = monter().findComponent({ name: 'DataFilters' });
        expect(filtres.props('triggerOnly')).toBe(true);
        expect(filtres.props('url')).toBe('/backoffice/absences');
        expect(filtres.props('sites')).toEqual([{ id: 's1', nom: 'Matoto' }]);
        expect(
            (filtres.props('fields') as { key: string; type: string }[]).map(
                (f) => `${f.key}:${f.type}`,
            ),
        ).toEqual([
            'statut:select',
            'recherche:text',
            'periode:period',
            'motif_id:select',
            'auteur_id:select',
        ]);
    });

    it('n’affiche « Nouvelle absence » qu’avec la permission de saisir', () => {
        droits.create = true;
        expect(monter().find('[data-testid="absence-nouvelle"]').exists()).toBe(
            true,
        );
        droits.create = false;
        expect(monter().find('[data-testid="absence-nouvelle"]').exists()).toBe(
            false,
        );
        droits.create = true;
    });

    it('ne propose que les actions acceptées par le serveur', () => {
        const texte = monter().text();
        expect(texte).toContain('Modifier');
        expect(texte).not.toContain('Annuler');
    });
});
