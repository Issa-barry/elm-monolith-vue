import ClientsShow from '@/pages/Clients/Show.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const permissions = vi.hoisted(() => ({ accordees: new Set<string>() }));
const post = vi.hoisted(() => vi.fn());

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({
        can: (permission: string) => permissions.accordees.has(permission),
    }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: () => {} }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            post,
            patch: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            defaults: vi.fn(),
            transform: () => form,
        });

        return form;
    },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const client = (qualifie: boolean) => ({
    id: 1,
    nom_complet: 'Yousssouf KOUROUMA',
    contact_nom: null,
    telephone: '620454548',
    adresse: null,
    ville: null,
    pays: 'Guinée',
    code_pays: 'GN',
    code_phone_pays: '+224',
    is_active: true,
    type: 'externe',
    type_label: 'Externe',
    qualifie,
    cashback_eligible: false,
    cashback_montant_par_pack: null,
    derogation_impayes_autorisee: false,
    seuil_derogation_impayes: null,
});

const monter = (qualifie: boolean) =>
    shallowMount(ClientsShow, {
        props: {
            client: client(qualifie),
            types: [
                { value: 'externe', label: 'Externe' },
                { value: 'revendeur', label: 'Revendeur' },
            ],
            cashback_solde: null,
            seuil_global_impayes: 0,
            tarifs_grossiste: { categories: [], tarifs: [] },
            mode_remise_grossiste_options: [],
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Clients — qualification commerciale (ADR 0066)', () => {
    beforeEach(() => {
        permissions.accordees = new Set(['clients.read']);
        post.mockReset();
    });

    it('signale un client créé à l’inscription, sans action sans la permission dédiée', () => {
        const page = monter(false);

        expect(
            page.find('[data-testid="client-a-qualifier-message"]').exists(),
        ).toBe(true);
        expect(
            page.find('[data-testid="client-qualifier-button"]').exists(),
        ).toBe(false);
    });

    it('propose l’action Qualifier avec la permission clients.qualifier', () => {
        permissions.accordees.add('clients.qualifier');

        expect(
            monter(false)
                .find('[data-testid="client-qualifier-button"]')
                .exists(),
        ).toBe(true);
    });

    it('n’affiche rien pour un client déjà qualifié', () => {
        permissions.accordees.add('clients.qualifier');

        expect(
            monter(true)
                .find('[data-testid="client-a-qualifier-message"]')
                .exists(),
        ).toBe(false);
    });
});
