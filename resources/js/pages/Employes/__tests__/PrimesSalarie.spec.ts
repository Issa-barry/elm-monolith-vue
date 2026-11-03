import PrimesSalarie, {
    type Affectation,
    type PrimesOptions,
} from '@/pages/Employes/partials/PrimesSalarie.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const permissions = new Set<string>();
vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: (p: string) => permissions.has(p) }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
vi.mock('@inertiajs/vue3', () => ({
    router: { patch: vi.fn(), delete: vi.fn() },
    useForm: (donnees: Record<string, unknown>) =>
        reactive({
            ...donnees,
            errors: {},
            processing: false,
            reset: vi.fn(),
            clearErrors: vi.fn(),
            transform: vi.fn(),
        }),
}));

const prime = (surcharge: Partial<Affectation> = {}): Affectation =>
    ({
        id: 'P1',
        employe: { id: 'E1', nom_complet: 'Moussa Sidibe' },
        type: {
            id: 'T1',
            libelle: 'Transport',
            valorise: false,
            mode_calcul: 'montant_fixe',
            periodicite: 'mensuelle',
            nature_montant: 'fixe',
            statut: 'actif',
        },
        date_debut: '2026-11-01',
        date_fin: null,
        montant: 500000,
        taux: null,
        valeur_label: '500 000 GNF',
        statut: 'validee',
        etat: 'en_cours',
        etat_label: 'En cours',
        attend_validation: false,
        est_changement: false,
        successeur_statut: null,
        demande_fin: null,
        motif_fin: null,
        est_transmise: false,
        peut_agir: true,
        saisies: [],
        cree_par: null,
        valide_par: 'Issa BARRY',
        ...surcharge,
    }) as Affectation;

const options = (surcharge: Partial<PrimesOptions> = {}): PrimesOptions => ({
    types: [],
    primes_occupees: [],
    premier_jour_modifiable: null,
    peut_attribuer: true,
    ...surcharge,
});

const monter = (primes: Affectation[], opts: PrimesOptions = options()) =>
    shallowMount(PrimesSalarie, {
        props: { employeId: 'E1', primes, options: opts },
        global: { renderStubDefaultSlot: true },
    });

describe('Primes sur la fiche salarié', () => {
    beforeEach(() => {
        permissions.clear();
        permissions.add('rh-affectations-remuneration.read');
    });

    it('propose « Ajouter une prime » avec le droit et sur un salarié de ses agences', () => {
        const bouton = '[data-testid="prime-ajouter"]';

        expect(monter([]).find(bouton).exists()).toBe(false);

        permissions.add('rh-affectations-remuneration.create');
        expect(monter([]).find(bouton).exists()).toBe(true);
        expect(
            monter([], options({ peut_attribuer: false }))
                .find(bouton)
                .exists(),
        ).toBe(false);
    });

    it('n’affiche le bouton Valider qu’avec le droit de validation', () => {
        const aValider = prime({
            statut: 'a_valider',
            etat: 'a_valider',
            etat_label: 'À valider',
            attend_validation: true,
        });
        const bouton = '[data-testid="affectation-valider"]';

        expect(monter([aValider]).find(bouton).exists()).toBe(false);

        permissions.add('rh-affectations-remuneration.valider');
        expect(monter([aValider]).find(bouton).exists()).toBe(true);
        expect(monter([prime()]).find(bouton).exists()).toBe(false);
        expect(
            monter([{ ...aValider, peut_agir: false }])
                .find(bouton)
                .exists(),
        ).toBe(false);
    });

    it('garde les anciennes primes repliées, sans les perdre', async () => {
        const wrapper = monter([
            prime(),
            prime({ id: 'P2', etat: 'terminee', etat_label: 'Terminée' }),
            prime({
                id: 'P3',
                statut: 'annulee',
                etat: 'annulee',
                etat_label: 'Annulée',
            }),
        ]);

        expect(wrapper.find('[data-testid="prime-P1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="prime-P2"]').exists()).toBe(false);

        const repli = wrapper.find('[data-testid="primes-anciennes"]');
        expect(repli.text()).toContain('Voir les anciennes primes (2)');

        await repli.trigger('click');
        expect(wrapper.find('[data-testid="prime-P2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="prime-P3"]').exists()).toBe(true);
    });

    it('laisse visible une prime terminée dont une fin attend encore sa validation', () => {
        const wrapper = monter([
            prime({ etat: 'terminee', attend_validation: true }),
        ]);

        expect(wrapper.find('[data-testid="prime-P1"]').exists()).toBe(true);
    });

    it('indique qu’aucune prime n’est attribuée', () => {
        expect(monter([]).text()).toContain(
            'Aucune prime ni aucun avantage attribué.',
        );
    });
});
