import Organisation from '@/pages/settings/Organisation.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const ajouterToast = vi.fn();
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: ajouterToast }),
}));

let options: { onSuccess?: () => void; onError?: () => void } = {};
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        useForm: (donnees: Record<string, unknown>) =>
            reactive({
                ...donnees,
                errors: {},
                processing: false,
                recentlySuccessful: false,
                post: (_url: string, opts: typeof options) => {
                    options = opts;
                },
            }),
    };
});

const monter = () =>
    shallowMount(Organisation, {
        props: {
            organisation: {
                name: 'Eau La Maman',
                slug: 'eau-la-maman',
                code: 'ELA',
                siret: null,
                logo_url: null,
                pied_page_documents: null,
            },
            login_url: 'http://localhost/login/ELA',
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Paramètres → Organisation — enregistrement', () => {
    beforeEach(() => {
        ajouterToast.mockClear();
        options = {};
    });

    it('affiche un toast de succès à chaque enregistrement', async () => {
        const wrapper = monter();

        for (let i = 0; i < 2; i++) {
            await wrapper.find('form').trigger('submit');
            options.onSuccess?.();
        }

        expect(ajouterToast).toHaveBeenCalledTimes(2);
        expect(ajouterToast).toHaveBeenLastCalledWith(
            expect.objectContaining({ group: 'top', severity: 'success' }),
        );
    });

    it('affiche un toast d’erreur quand la saisie est refusée', async () => {
        const wrapper = monter();

        await wrapper.find('form').trigger('submit');
        options.onError?.();

        expect(ajouterToast).toHaveBeenCalledWith(
            expect.objectContaining({ group: 'top', severity: 'error' }),
        );
    });
});
