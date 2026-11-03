import { router } from '@inertiajs/vue3';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import DetachementCompteDialog from '../DetachementCompteDialog.vue';
import RattachementCompteDialog from '../RattachementCompteDialog.vue';
import type { CompteCandidat } from '../types';

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: vi.fn(), post: vi.fn() },
}));

// Dialog PrimeVue remplacé par un conteneur simple : contenu et pied rendus si visible.
const DialogStub = defineComponent({
    props: { visible: Boolean },
    emits: ['update:visible'],
    setup(props, { slots }) {
        return () =>
            props.visible
                ? h('div', [slots.default?.(), slots.footer?.()])
                : null;
    },
});

const CheckboxStub = defineComponent({
    props: { modelValue: Boolean },
    emits: ['update:modelValue'],
    setup(props, { emit, attrs }) {
        return () =>
            h('button', {
                ...attrs,
                type: 'button',
                'data-coche': props.modelValue,
                onClick: () => emit('update:modelValue', !props.modelValue),
            });
    },
});

const PhoneStub = defineComponent({
    props: { modelValue: String },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () =>
            h('input', {
                'data-testid': 'telephone',
                value: props.modelValue,
                onInput: (e: Event) =>
                    emit(
                        'update:modelValue',
                        (e.target as HTMLInputElement).value,
                    ),
            });
    },
});

const global = {
    plugins: [PrimeVue],
    stubs: {
        Dialog: DialogStub,
        Checkbox: CheckboxStub,
        PhoneCountryInput: PhoneStub,
    },
};

const candidat = (attributs: Partial<CompteCandidat> = {}): CompteCandidat => ({
    id: 'u1',
    nom: 'Awa Bah',
    telephone: '+224620000321',
    email: null,
    inscrit_le: '2026-10-01T10:00:00+00:00',
    roles: ['client'],
    motif_refus: null,
    ...attributs,
});

function repondre(compte: CompteCandidat | null) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ compte }),
        }),
    );
}

async function ouvrir(): Promise<VueWrapper> {
    const wrapper = mount(RattachementCompteDialog, {
        props: {
            visible: true,
            parrainId: 'p1',
            telephoneParrain: '+224620000321',
        },
        global,
    });
    await flushPromises();

    return wrapper;
}

const bouton = (wrapper: VueWrapper, testId: string) =>
    wrapper.find(`[data-testid="${testId}"]`);

describe('RattachementCompteDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('cherche le numéro du parrain à l’ouverture et ne relie rien', async () => {
        repondre(candidat());
        const wrapper = await ouvrir();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(vi.mocked(fetch).mock.calls[0][0]).toContain(
            '/backoffice/parrains/p1/compte/candidat?telephone=%2B224620000321',
        );
        expect(wrapper.text()).toContain('Awa Bah');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('exige la vérification de l’identité avant de confirmer', async () => {
        repondre(candidat());
        const wrapper = await ouvrir();

        const confirmer = bouton(wrapper, 'rattachement-confirmer');
        expect(confirmer.attributes('disabled')).toBeDefined();

        await bouton(wrapper, 'rattachement-identite').trigger('click');
        expect(confirmer.attributes('disabled')).toBeUndefined();

        await confirmer.trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0].slice(0, 2)).toEqual([
            '/backoffice/parrains/p1/compte',
            { compte_id: 'u1', identite_verifiee: true },
        ]);
    });

    it('affiche le motif de refus et bloque la confirmation', async () => {
        repondre(
            candidat({
                motif_refus: 'Ce compte est déjà rattaché à un autre parrain.',
            }),
        );
        const wrapper = await ouvrir();

        expect(bouton(wrapper, 'rattachement-motif-refus').text()).toContain(
            'déjà rattaché',
        );
        expect(bouton(wrapper, 'rattachement-identite').exists()).toBe(false);
        expect(
            bouton(wrapper, 'rattachement-confirmer').attributes('disabled'),
        ).toBeDefined();
    });

    it('n’affiche que le motif pour un compte d’une autre organisation', async () => {
        repondre(
            candidat({
                id: null,
                nom: null,
                telephone: null,
                roles: [],
                motif_refus: 'Ce compte appartient à une autre organisation.',
            }),
        );
        const wrapper = await ouvrir();

        expect(wrapper.text()).not.toContain('Nom saisi');
        expect(wrapper.text()).toContain('autre organisation');
    });

    it('signale qu’aucun compte n’utilise ce numéro', async () => {
        repondre(null);
        const wrapper = await ouvrir();

        expect(bouton(wrapper, 'rattachement-aucun-compte').exists()).toBe(
            true,
        );
    });

    it('efface le compte proposé quand le numéro change', async () => {
        repondre(candidat());
        const wrapper = await ouvrir();
        await bouton(wrapper, 'rattachement-identite').trigger('click');

        await bouton(wrapper, 'telephone').setValue('+224620000999');
        await nextTick();

        expect(bouton(wrapper, 'rattachement-candidat').exists()).toBe(false);
        expect(
            bouton(wrapper, 'rattachement-confirmer').attributes('disabled'),
        ).toBeDefined();
    });

    it('empêche le double envoi et la fermeture pendant la confirmation', async () => {
        repondre(candidat());
        const wrapper = await ouvrir();
        await bouton(wrapper, 'rattachement-identite').trigger('click');

        const vm = wrapper.vm as unknown as {
            confirmer: () => void;
            fermer: (visible: boolean) => void;
        };
        vm.confirmer();
        vm.confirmer();
        vm.fermer(false);
        await nextTick();

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('update:visible')).toBeUndefined();
        expect(wrapper.text()).toContain('Confirmation…');
    });

    it('affiche le refus renvoyé par le serveur', async () => {
        repondre(candidat());
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({ compte: 'Cette fiche est désactivée.' });
            options?.onFinish?.({} as never);
        });
        const wrapper = await ouvrir();
        await bouton(wrapper, 'rattachement-identite').trigger('click');

        await bouton(wrapper, 'rattachement-confirmer').trigger('click');

        expect(bouton(wrapper, 'rattachement-erreur').text()).toContain(
            'désactivée',
        );
        expect(wrapper.emitted('update:visible')).toBeUndefined();
    });
});

describe('DetachementCompteDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    function monter() {
        return mount(DetachementCompteDialog, {
            props: { visible: true, parrainId: 'p1', nomCompte: 'Awa Bah' },
            global,
        });
    }

    it('annonce que le compte est conservé et ne détache qu’après confirmation', async () => {
        const wrapper = monter();

        expect(wrapper.text()).toContain('Awa Bah');
        expect(wrapper.text()).toContain('pas supprimé');
        expect(router.delete).not.toHaveBeenCalled();

        await bouton(wrapper, 'detachement-confirmer').trigger('click');

        expect(vi.mocked(router.delete).mock.calls[0][0]).toBe(
            '/backoffice/parrains/p1/compte',
        );
    });

    it('empêche le double envoi et la fermeture pendant le détachement', async () => {
        const wrapper = monter();
        const vm = wrapper.vm as unknown as {
            confirmer: () => void;
            fermer: (visible: boolean) => void;
        };

        vm.confirmer();
        vm.confirmer();
        vm.fermer(false);
        await nextTick();

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('update:visible')).toBeUndefined();
        expect(wrapper.text()).toContain('Détachement…');
    });
});
