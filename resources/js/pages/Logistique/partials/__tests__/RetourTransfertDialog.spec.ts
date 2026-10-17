import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import RetourTransfertDialog from '../RetourTransfertDialog.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));

const DialogStub = defineComponent({
    props: { visible: Boolean },
    setup:
        (props, { slots }) =>
        () =>
            props.visible
                ? h('div', { 'data-testid': 'dialog' }, [
                      slots.default?.(),
                      h('footer', slots.footer?.()),
                  ])
                : null,
});

interface LigneTest {
    id: string;
    produit_nom: string;
    quantite_chargee: number;
    quantite_recue: number | null;
    quantite_retournee: number;
    quantite_retournable: number;
}

const LIGNES: LigneTest[] = [
    {
        id: 'l1',
        produit_nom: 'Eau 19L',
        quantite_chargee: 30,
        quantite_recue: null,
        quantite_retournee: 10,
        quantite_retournable: 20,
    },
    {
        id: 'l2',
        produit_nom: 'Eau 1,5L',
        quantite_chargee: 12,
        quantite_recue: null,
        quantite_retournee: 0,
        quantite_retournable: 12,
    },
];

const MOTIFS = [
    { value: 'refus_reception', label: 'Refus de réception' },
    { value: 'autre', label: 'Autre' },
];

function monter(statut = 'transit', lignes: LigneTest[] = LIGNES) {
    return mount(RetourTransfertDialog, {
        props: {
            visible: true,
            transfertId: 't1',
            statut,
            siteSourceNom: 'Kouria',
            lignes,
            motifs: MOTIFS,
        },
        global: {
            stubs: {
                Dialog: DialogStub,
                InputNumber: true,
                Select: true,
                Textarea: true,
            },
        },
    });
}

function boutonEnregistrer(wrapper: ReturnType<typeof monter>) {
    return wrapper
        .findAll('button')
        .find((b) => b.text().includes('Enregistrer le retour'))!;
}

describe('RetourTransfertDialog', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('en livraison : tout retourner annonce le retour total et envoie les quantités restantes', async () => {
        const wrapper = monter();
        const vm = wrapper.vm as unknown as {
            toutRetourner: () => void;
            motif: string | null;
        };

        expect(wrapper.text()).toContain('Kouria');
        expect(wrapper.text()).toContain('Reste à livrer');
        expect(wrapper.text()).not.toContain('Non justifié');
        expect(boutonEnregistrer(wrapper).attributes('disabled')).toBeDefined();

        vm.toutRetourner();
        vm.motif = 'refus_reception';
        await nextTick();

        expect(wrapper.text()).toContain('Retour total');
        expect(
            boutonEnregistrer(wrapper).attributes('disabled'),
        ).toBeUndefined();

        await boutonEnregistrer(wrapper).trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/backoffice/logistique/t1/retour',
            {
                motif: 'refus_reception',
                commentaire: null,
                lignes: [
                    { id: 'l1', quantite: 20 },
                    { id: 'l2', quantite: 12 },
                ],
            },
            expect.any(Object),
        );
    });

    it('après réception : seul le non-reçu est proposé et le transfert ne devient jamais « Retourné »', async () => {
        const wrapper = monter('reception', [
            {
                id: 'l1',
                produit_nom: 'Eau 19L',
                quantite_chargee: 30,
                quantite_recue: 25,
                quantite_retournee: 0,
                quantite_retournable: 5,
            },
        ]);
        const vm = wrapper.vm as unknown as { toutRetourner: () => void };

        expect(wrapper.text()).toContain('Non justifié');
        expect(wrapper.text()).toContain('Seule la marchandise non reçue');

        vm.toutRetourner();
        await nextTick();

        expect(wrapper.text()).not.toContain('Retour total');
    });

    it('le motif « Autre » exige un commentaire', async () => {
        const wrapper = monter();
        const vm = wrapper.vm as unknown as {
            retourLignes: { quantite: number }[];
            motif: string | null;
            commentaire: string;
        };

        vm.retourLignes[0].quantite = 3;
        vm.motif = 'autre';
        await nextTick();
        expect(boutonEnregistrer(wrapper).attributes('disabled')).toBeDefined();

        vm.commentaire = 'Camion en panne';
        await nextTick();
        expect(
            boutonEnregistrer(wrapper).attributes('disabled'),
        ).toBeUndefined();
        expect(wrapper.text()).not.toContain('Retour total');
    });
});
