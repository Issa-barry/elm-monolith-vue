import ContratsIndex from '@/pages/Contrats/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, inject, provide } from 'vue';

const permissions = new Set<string>();
vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: (p: string) => permissions.has(p) }),
}));
vi.mock('primevue/useconfirm', () => ({
    useConfirm: () => ({ require: vi.fn() }),
}));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), delete: vi.fn(), visit: vi.fn() },
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
    setup() {
        return { ligne: inject('ligne') };
    },
    template: '<div><slot name="body" :data="ligne" /></div>',
});

const contrat = (surcharge: Record<string, unknown> = {}) => ({
    id: '01JCONTRAT',
    employe_id: '01JEMPLOYE',
    employe_nom_complet: 'Ousmane SIDIBÉ',
    employe_matricule: '751450',
    type_contrat: 'cdi',
    type_contrat_label: 'CDI',
    statut_contrat: 'actif',
    statut_contrat_label: 'Actif',
    date_debut: '2026-10-01',
    date_fin: null,
    salaire_base: '750000',
    document_courant: { version: 1, statut: 'signe', statut_label: 'Signé' },
    peut_generer: true,
    primes_count: 0,
    ...surcharge,
});

const monter = (surcharge: Record<string, unknown> = {}) =>
    shallowMount(ContratsIndex, {
        props: {
            contrats: [contrat(surcharge)],
            filters: {},
            sites: [],
            type_contrat_options: [],
            statut_contrat_options: [],
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: { DataTable, Column, Ligne },
        },
    });

const actions = (wrapper: ReturnType<typeof monter>) =>
    wrapper
        .findAll('[data-testid^="action-"]')
        .map((el) => el.attributes('data-testid'));

describe('Contrats — menu de ligne', () => {
    beforeEach(() => {
        permissions.clear();
        for (const p of [
            'rh-contrats.read',
            'rh-contrats.update',
            'rh-contrats.delete',
            'rh-remunerations.read',
        ]) {
            permissions.add(p);
        }
    });

    it('propose une seule action « Voir » qui ouvre le PDF dans le navigateur', () => {
        const wrapper = monter();

        expect(actions(wrapper)).toEqual([
            'action-voir-contrat-pdf',
            'action-versions-contrat',
        ]);
        const lien = wrapper.find('[data-testid="action-voir-contrat-pdf"] a');
        expect(lien.attributes('href')).toBe(
            '/backoffice/contrats/01JCONTRAT/pdf?preview=1',
        );
        expect(lien.attributes('target')).toBe('_blank');
        expect(lien.attributes('rel')).toBe('noopener noreferrer');
        expect(lien.attributes('download')).toBeUndefined();
        expect(lien.text()).toBe('Voir');
        expect(wrapper.text()).not.toContain('Télécharger');
        expect(wrapper.find('a[href$="/pdf"]').exists()).toBe(false);
    });

    it('conserve la fiche, Modifier et Supprimer selon les permissions', () => {
        const wrapper = monter();

        expect(
            wrapper.find('[data-testid="action-versions-contrat"]').text(),
        ).toBe('Fiche du contrat, signature');
        expect(
            wrapper
                .find('[href="/backoffice/contrats/01JCONTRAT/edit"]')
                .text(),
        ).toBe('Modifier');
        expect(wrapper.text()).toContain('Supprimer');

        permissions.delete('rh-contrats.update');
        permissions.delete('rh-contrats.delete');
        const lecture = monter({ peut_generer: false });
        expect(
            lecture
                .find('[href="/backoffice/contrats/01JCONTRAT/edit"]')
                .exists(),
        ).toBe(false);
        expect(lecture.text()).not.toContain('Supprimer');
        expect(
            lecture.find('[data-testid="action-versions-contrat"]').text(),
        ).toBe('Fiche du contrat');
    });

    it('n’affiche pas « Voir » sans le droit de voir les salaires', () => {
        permissions.delete('rh-remunerations.read');

        expect(actions(monter())).toEqual([]);
    });

    it('n’affiche pas « Voir » tant qu’aucun PDF n’existe', () => {
        expect(actions(monter({ document_courant: null }))).toEqual([
            'action-preparer-pdf',
        ]);
    });
});

describe('Contrats — colonne Primes', () => {
    beforeEach(() => {
        permissions.clear();
        permissions.add('rh-contrats.read');
        permissions.add('rh-affectations-remuneration.read');
    });

    const nombre = (primes_count: number) =>
        monter({ primes_count }).find('[data-testid="nombre-primes"]');

    it('affiche le nombre de primes attribuées au salarié', () => {
        expect(nombre(0).text()).toBe('0 prime');
        expect(nombre(0).classes()).toContain('text-muted-foreground');
        expect(nombre(1).text()).toBe('1 prime');
        expect(nombre(1).classes()).not.toContain('text-muted-foreground');
        expect(nombre(3).text()).toBe('3 primes');
    });

    it('n’affiche pas la colonne sans le droit de consulter les primes', () => {
        permissions.delete('rh-affectations-remuneration.read');

        expect(
            monter({ primes_count: null })
                .find('[data-testid="nombre-primes"]')
                .exists(),
        ).toBe(false);
    });
});
