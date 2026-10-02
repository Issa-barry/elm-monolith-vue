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
        const wrapper = monter({
            especesDisponibles: false,
            moyens: MOYENS_AGENCE,
        });

        expect(optionDesactivee(wrapper, 'especes')).toBe(true);

        const message = wrapper.find('[data-testid="especes-indisponible"]');
        expect(message.exists()).toBe(true);
        expect(message.text()).toBe(
            'Espèces indisponibles : aucune caisse active.',
        );
        // Attention (autre mode possible), jamais une erreur rouge.
        expect(message.classes().join(' ')).toContain('amber');
    });

    it('affiche une seule alerte quand aucun moyen de paiement ne fonctionne', () => {
        const wrapper = monter({ especesDisponibles: false });

        expect(wrapper.get('[data-testid="especes-indisponible"]').text()).toBe(
            'Aucun moyen de paiement disponible. Contactez votre responsable.',
        );
        expect(wrapper.find('[data-testid="aucun-autre-moyen"]').exists()).toBe(
            false,
        );
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
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
        expect(info.text()).toBe('Espèces uniquement.');
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

    describe('décaissement (paiement de fiche, ADR 0009)', () => {
        const MOYENS_AVEC_SOLDE = MOYENS_AGENCE.map((m) => ({
            ...m,
            solde_disponible:
                m.compte_tresorerie_id === 's-orange' ? 50_000 : 1_000_000,
        }));

        it('affiche le solde disponible de la caisse pour les espèces', () => {
            const wrapper = monter({
                sens: 'decaissement',
                moyens: MOYENS_AVEC_SOLDE,
                soldeEspeces: 250_000,
            });

            expect(
                wrapper.get('[data-testid="solde-disponible"]').text(),
            ).toContain('Disponible');
            expect(confirmer(wrapper).attributes('disabled')).toBeUndefined();
        });

        it('bloque Confirmer quand le montant dépasse le solde du support choisi', async () => {
            const wrapper = monter({
                sens: 'decaissement',
                moyens: MOYENS_AVEC_SOLDE,
                soldeEspeces: 250_000,
            });

            select(wrapper).vm.$emit(
                'update:modelValue',
                'mobile_money:s-orange',
            );
            await nextTick();
            wrapper
                .findComponent(InputTexteFactice)
                .vm.$emit('update:modelValue', 'OM-1');
            await nextTick();

            const solde = wrapper.get('[data-testid="solde-disponible"]');
            expect(solde.text()).toContain('Solde insuffisant');
            expect(solde.classes()).toContain('text-destructive');
            expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
            await confirmer(wrapper).trigger('click');
            expect(wrapper.emitted('submit')).toBeUndefined();
        });

        it('parle de payer, pas d’encaisser, quand le payeur n’a pas de caisse', () => {
            const wrapper = monter({
                sens: 'decaissement',
                moyens: MOYENS_AVEC_SOLDE,
                especesDisponibles: false,
            });

            expect(
                wrapper.get('[data-testid="especes-indisponible"]').text(),
            ).toContain('Paiement en espèces indisponible');
        });

        it('n’affiche aucun solde en encaissement', () => {
            const wrapper = monter({
                moyens: MOYENS_AVEC_SOLDE,
                soldeEspeces: 10,
            });

            expect(
                wrapper.find('[data-testid="solde-disponible"]').exists(),
            ).toBe(false);
        });
    });
});

describe('PaymentCard — agence d’encaissement (ADR 0012)', () => {
    const MOYEN_KINDIA = {
        key: 'mobile_money:s-kindia',
        label: 'Orange Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'orange_money',
        compte_tresorerie_id: 's-kindia',
        reference_requise: true,
    };
    const AGENCES = [
        {
            site_id: 'site-a',
            nom: 'Matoto',
            moyens: MOYENS_AGENCE,
            peut_encaisser_especes: true,
        },
        {
            site_id: 'site-b',
            nom: 'Kindia',
            moyens: [MOYEN_KINDIA],
            peut_encaisser_especes: false,
        },
    ];
    const AGENCE_COMMANDE = { id: 'site-a', nom: 'Matoto' };

    const selects = (wrapper: ReturnType<typeof monter>) =>
        wrapper.findAllComponents(SelectFactice);
    const selectAgence = (wrapper: ReturnType<typeof monter>) =>
        selects(wrapper).find((s) => s.props('optionValue') === 'site_id')!;
    const selectMode = (wrapper: ReturnType<typeof monter>) =>
        selects(wrapper).find((s) => s.props('optionValue') === 'key')!;
    const clesModes = (wrapper: ReturnType<typeof monter>) =>
        (selectMode(wrapper).props('options') as { key: string }[]).map(
            (o) => o.key,
        );

    it('n’affiche aucun choix d’agence sur les écrans existants', () => {
        const wrapper = monter({ moyens: MOYENS_AGENCE });

        expect(
            wrapper.find('[data-testid="agence-encaissement"]').exists(),
        ).toBe(false);
    });

    it('présélectionne l’agence de la commande et en propose les moyens, sans bandeau', () => {
        const wrapper = monter({
            agences: AGENCES,
            agenceDefaut: 'site-a',
            agenceCommande: AGENCE_COMMANDE,
        });

        expect(selectAgence(wrapper).props('modelValue')).toBe('site-a');
        expect(clesModes(wrapper)).toEqual([
            'especes',
            ...MOYENS_AGENCE.map((m) => m.key),
        ]);
        expect(
            wrapper.find('[data-testid="bandeau-autre-agence"]').exists(),
        ).toBe(false);
    });

    it('suit les moyens et la caisse de l’agence choisie et annonce le reversement', async () => {
        const wrapper = monter({
            agences: AGENCES,
            agenceDefaut: 'site-a',
            agenceCommande: AGENCE_COMMANDE,
        });

        selectAgence(wrapper).vm.$emit('update:modelValue', 'site-b');
        await nextTick();

        expect(clesModes(wrapper)).toEqual(['especes', MOYEN_KINDIA.key]);
        // Pas de caisse dédiée à Kindia : espèces désactivées, rien de présélectionné.
        expect(selectMode(wrapper).props('modelValue')).toBe('');

        const bandeau = wrapper.get('[data-testid="bandeau-autre-agence"]');
        expect(bandeau.text()).toBe('Kindia devra reverser à Matoto.');
    });

    it('soumet l’agence d’encaissement avec le support de cette agence', async () => {
        const wrapper = monter({
            agences: AGENCES,
            agenceDefaut: 'site-a',
            agenceCommande: AGENCE_COMMANDE,
        });

        selectAgence(wrapper).vm.$emit('update:modelValue', 'site-b');
        await nextTick();
        selectMode(wrapper).vm.$emit('update:modelValue', MOYEN_KINDIA.key);
        await nextTick();
        wrapper
            .findComponent(InputTexteFactice)
            .vm.$emit('update:modelValue', 'OM-9');
        await nextTick();
        await confirmer(wrapper).trigger('click');

        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    montant: 100_000,
                    mode_paiement: 'mobile_money',
                    compte_tresorerie_id: 's-kindia',
                    reference_paiement: 'OM-9',
                    site_encaissement_id: 'site-b',
                },
            ],
        ]);
    });

    it('affiche l’unique agence possible sans liste déroulante', () => {
        const wrapper = monter({
            agences: [AGENCES[1]],
            agenceDefaut: 'site-b',
            agenceCommande: AGENCE_COMMANDE,
        });

        expect(selectAgence(wrapper)).toBeUndefined();
        expect(
            wrapper.get('[data-testid="agence-encaissement"]').text(),
        ).toContain('Kindia');
        expect(
            wrapper.find('[data-testid="bandeau-autre-agence"]').exists(),
        ).toBe(true);
    });
});

describe('PaymentCard — agences fournies par l’écran (encaissementAgences)', () => {
    it('présélectionne l’agence de l’utilisateur et annonce le reversement', () => {
        const wrapper = monter({
            encaissementAgences: {
                agences: [
                    {
                        site_id: 'cba',
                        nom: 'CBA',
                        moyens: [],
                        peut_encaisser_especes: true,
                    },
                ],
                agence_defaut: 'cba',
                agence_commande: { id: 'matoto', nom: 'Matoto' },
                message: null,
            },
        });

        expect(
            wrapper.get('[data-testid="agence-encaissement"]').text(),
        ).toContain('CBA');
        expect(
            wrapper.get('[data-testid="bandeau-autre-agence"]').text(),
        ).toContain('CBA devra reverser à Matoto.');
        expect(confirmer(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('bloque l’encaissement et explique pourquoi quand l’utilisateur n’a aucune agence possible', async () => {
        const wrapper = monter({
            encaissementAgences: {
                agences: [],
                agence_defaut: null,
                agence_commande: { id: 'matoto', nom: 'Matoto' },
                message:
                    "Vous n'êtes affecté à aucune agence. Vous ne pouvez pas effectuer cet encaissement.",
            },
        });

        const refus = wrapper.get('[data-testid="refus-agence-encaissement"]');
        expect(refus.text()).toContain('Vous n');
        // Opération réellement bloquée : rouge.
        expect(refus.classes().join(' ')).toContain('destructive');
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
        await confirmer(wrapper).trigger('click');
        expect(wrapper.emitted('submit')).toBeUndefined();
    });
});
