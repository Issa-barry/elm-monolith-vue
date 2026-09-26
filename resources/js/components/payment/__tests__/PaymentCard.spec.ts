import PaymentCard from '@/components/payment/PaymentCard.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

// Directive locale de PaymentCard : sans le plugin PrimeVue installé, on ne veut pas de tooltip.
vi.mock('primevue/tooltip', () => ({ default: {} }));

// Le Select PrimeVue hérite de ses props (`extends`) : son stub automatique n'en expose aucune. On le
// remplace par un composant qui déclare celles qui nous intéressent.
const SelectFactice = defineComponent({
    props: {
        modelValue: { type: null, default: null },
        options: { type: Array, default: () => [] },
        optionLabel: { type: String, default: '' },
        optionValue: { type: String, default: '' },
        optionDisabled: { type: Function, default: null },
        placeholder: { type: String, default: '' },
    },
    emits: ['update:modelValue'],
    template: '<div data-testid="select" />',
});

const InputTexteFactice = defineComponent({
    props: { modelValue: { type: String, default: '' } },
    emits: ['update:modelValue'],
    template: '<input data-testid="reference" />',
});

const DialogFactice = defineComponent({
    template: '<div><slot /><slot name="footer" /></div>',
});

const InputNumberFactice = defineComponent({
    props: { modelValue: { type: null, default: null } },
    template: '<input data-testid="montant" />',
});

// Moyens hors espèces tels que le backend les fournit (un par support actif de l'agence).
const MOYENS_AGENCE = [
    {
        key: 'mobile_money:s-orange',
        label: 'Orange Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'orange_money',
        compte_tresorerie_id: 's-orange',
        reference_requise: true,
    },
    {
        key: 'virement:s-uba',
        label: 'Virement bancaire — UBA',
        mode_paiement: 'virement',
        operateur_mobile_money: null,
        compte_tresorerie_id: 's-uba',
        reference_requise: true,
    },
    {
        key: 'cheque:s-uba',
        label: 'Chèque — UBA',
        mode_paiement: 'cheque',
        operateur_mobile_money: null,
        compte_tresorerie_id: 's-uba',
        reference_requise: false,
    },
];

const monter = (props: Record<string, unknown> = {}) =>
    mount(PaymentCard, {
        props: { visible: true, title: 'Encaisser', solde: 100_000, ...props },
        global: {
            stubs: {
                Dialog: DialogFactice,
                Select: SelectFactice,
                InputNumber: InputNumberFactice,
                InputText: InputTexteFactice,
            },
        },
    });

const select = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findComponent(SelectFactice);

const confirmer = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findAll('button').find((b) => b.text().includes('Confirmer'))!;

const optionDesactivee = (wrapper: ReturnType<typeof monter>, key: string) => {
    const options = select(wrapper).props('options') as { key: string }[];
    const option = options.find((o) => o.key === key);

    return (select(wrapper).props('optionDisabled') as (o: unknown) => boolean)(
        option,
    );
};

describe('PaymentCard — espèces et caisse dédiée', () => {
    it('propose les espèces par défaut quand l’utilisateur a une caisse active', () => {
        const wrapper = monter({ especesDisponibles: true });

        expect(select(wrapper).props('modelValue')).toBe('especes');
        expect(optionDesactivee(wrapper, 'especes')).toBe(false);
        expect(
            wrapper.find('[data-testid="especes-indisponible"]').exists(),
        ).toBe(false);
        expect(confirmer(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('ne bloque rien quand la page ne précise pas la disponibilité des espèces', () => {
        const wrapper = monter();

        expect(optionDesactivee(wrapper, 'especes')).toBe(false);
        expect(
            wrapper.find('[data-testid="especes-indisponible"]').exists(),
        ).toBe(false);
    });

    it('désactive les espèces et explique pourquoi quand il n’y a pas de caisse active', () => {
        const wrapper = monter({ especesDisponibles: false });

        expect(optionDesactivee(wrapper, 'especes')).toBe(true);

        const message = wrapper.find('[data-testid="especes-indisponible"]');
        expect(message.exists()).toBe(true);
        expect(message.text()).toContain(
            "Vous ne disposez pas d'une caisse active",
        );
        expect(message.text()).toContain('Contactez votre responsable');
        // Attention (autre mode possible), jamais une erreur rouge.
        expect(message.classes().join(' ')).toContain('amber');
    });

    it('laisse les autres moyens de l’agence disponibles sans caisse', () => {
        const wrapper = monter({
            especesDisponibles: false,
            moyens: MOYENS_AGENCE,
        });

        for (const moyen of MOYENS_AGENCE) {
            expect(optionDesactivee(wrapper, moyen.key)).toBe(false);
        }
    });

    it('ne présélectionne aucun autre mode à la place des espèces et bloque Confirmer', () => {
        const wrapper = monter({ especesDisponibles: false });

        expect(select(wrapper).props('modelValue')).toBe('');
        expect(select(wrapper).props('placeholder')).toBe(
            'Choisir un mode de paiement',
        );
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
    });

    it('permet d’encaisser en Mobile Money sans caisse, sur le support choisi avec la référence', async () => {
        const wrapper = monter({
            especesDisponibles: false,
            moyens: MOYENS_AGENCE,
        });

        select(wrapper).vm.$emit('update:modelValue', 'mobile_money:s-orange');
        await nextTick();
        wrapper
            .findComponent(InputTexteFactice)
            .vm.$emit('update:modelValue', 'OM-123');
        await nextTick();

        expect(confirmer(wrapper).attributes('disabled')).toBeUndefined();
        await confirmer(wrapper).trigger('click');

        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    montant: 100_000,
                    mode_paiement: 'mobile_money',
                    compte_tresorerie_id: 's-orange',
                    reference_paiement: 'OM-123',
                },
            ],
        ]);
    });

    it('ne soumet jamais des espèces quand elles sont indisponibles, même si la sélection est forcée', async () => {
        const wrapper = monter({ especesDisponibles: false });

        select(wrapper).vm.$emit('update:modelValue', 'especes');
        await nextTick();
        await confirmer(wrapper).trigger('click');

        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('soumet les espèces quand la caisse est active', async () => {
        const wrapper = monter({ especesDisponibles: true });

        await confirmer(wrapper).trigger('click');

        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    montant: 100_000,
                    mode_paiement: 'especes',
                    compte_tresorerie_id: undefined,
                    reference_paiement: undefined,
                },
            ],
        ]);
    });

    it('retire la sélection espèces si la caisse disparaît pendant que la fenêtre est ouverte', async () => {
        const wrapper = monter({ especesDisponibles: true });
        expect(select(wrapper).props('modelValue')).toBe('especes');

        await wrapper.setProps({ especesDisponibles: false });

        expect(select(wrapper).props('modelValue')).toBe('');
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
        expect(
            wrapper.find('[data-testid="especes-indisponible"]').exists(),
        ).toBe(true);
    });
});

describe('PaymentCard — moyens issus des supports de l’agence', () => {
    it('ne propose que les espèces et les moyens reçus, jamais une liste fixe d’opérateurs', () => {
        const wrapper = monter({ moyens: MOYENS_AGENCE });

        const libelles = (
            select(wrapper).props('options') as { label: string }[]
        ).map((o) => o.label);
        expect(libelles).toEqual([
            'Espèces',
            'Orange Money',
            'Virement bancaire — UBA',
            'Chèque — UBA',
        ]);
        expect(libelles).not.toContain('Kulu');
        expect(wrapper.find('[data-testid="aucun-autre-moyen"]').exists()).toBe(
            false,
        );
    });

    it('signale en information qu’aucun compte Mobile Money ni bancaire n’est actif dans l’agence', () => {
        const wrapper = monter();

        const options = select(wrapper).props('options') as { key: string }[];
        expect(options.map((o) => o.key)).toEqual(['especes']);

        const info = wrapper.find('[data-testid="aucun-autre-moyen"]');
        expect(info.exists()).toBe(true);
        // Information (bleu), jamais une erreur : c'est une configuration de l'agence.
        expect(info.classes().join(' ')).toContain('blue');
    });

    it('soumet le chèque sur la banque choisie, sans référence exigée', async () => {
        const wrapper = monter({ moyens: MOYENS_AGENCE });

        select(wrapper).vm.$emit('update:modelValue', 'cheque:s-uba');
        await nextTick();
        await confirmer(wrapper).trigger('click');

        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    montant: 100_000,
                    mode_paiement: 'cheque',
                    compte_tresorerie_id: 's-uba',
                    reference_paiement: undefined,
                },
            ],
        ]);
    });
});
