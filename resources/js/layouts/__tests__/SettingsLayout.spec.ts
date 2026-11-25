import SettingsLayout from '@/layouts/settings/Layout.vue';
import { shallowMount } from '@vue/test-utils';
import Select from 'primevue/select';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const etat = vi.hoisted(() => ({ telephone: false }));
const router = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@vueuse/core', async (original) => ({
    ...(await original<typeof import('@vueuse/core')>()),
    useMediaQuery: () => ref(etat.telephone),
}));

// Liste déroulante PrimeVue remplacée par un composant minimal : le test porte sur les
// sections proposées et la navigation, pas sur le rendu PrimeVue.
vi.mock('primevue/select', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'SelectDeTest',
            props: {
                options: { type: Array, default: () => [] },
                modelValue: null,
            },
            emits: ['update:modelValue'],
            setup: () => () => h('div'),
        }),
    };
});

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: [String, Object], default: '' } },
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        router,
        usePage: () => ({
            props: {
                auth: {
                    // Administrateur avec tous les droits : le menu le plus complet possible.
                    permissions: new Proxy({}, { get: () => true }),
                    roles: ['super_admin'],
                },
                module_flags: {},
            },
        }),
    };
});

type Groupe = { label: string; items: { label: string; value: string }[] };

function monter(telephone: boolean) {
    etat.telephone = telephone;
    return shallowMount(SettingsLayout, {
        global: { renderStubDefaultSlot: true },
    });
}

const sectionsMobile = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findComponent(Select).props('options') as Groupe[];

const libellesMobile = (wrapper: ReturnType<typeof monter>) =>
    sectionsMobile(wrapper).flatMap((g) => g.items.map((i) => i.label));

describe('Paramètres — navigation selon l’appareil', () => {
    beforeEach(() => vi.clearAllMocks());

    it('garde « Rôles et permissions » sur tablette et ordinateur', () => {
        const wrapper = monter(false);

        expect(wrapper.find('nav').text()).toContain('Rôles et permissions');
        expect(libellesMobile(wrapper)).toContain('Rôles et permissions');
    });

    it('masque « Rôles et permissions » sur téléphone, sans retirer les autres sections', () => {
        const wrapper = monter(true);
        const libelles = libellesMobile(wrapper);

        expect(libelles).not.toContain('Rôles et permissions');
        expect(wrapper.find('nav').text()).not.toContain(
            'Rôles et permissions',
        );
        for (const attendu of [
            'Profil',
            'Mot de passe',
            'Double authentification',
            'Apparence',
            "Informations de l'organisation",
            'Produits',
            'Communications',
        ]) {
            expect(libelles).toContain(attendu);
        }
    });

    it('regroupe les sections du téléphone comme le menu vertical', () => {
        const groupes = sectionsMobile(monter(true)).map((g) => g.label);

        expect(groupes.slice(0, 3)).toEqual([
            'Mon compte',
            'Organisation',
            'Gestion',
        ]);
    });

    it('ouvre la section choisie dans la liste du téléphone', async () => {
        const wrapper = monter(true);
        const produits = sectionsMobile(wrapper)
            .flatMap((g) => g.items)
            .find((i) => i.label === 'Produits')!;

        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', produits.value);

        expect(router.visit).toHaveBeenCalledWith('/settings/produits');
    });
});
