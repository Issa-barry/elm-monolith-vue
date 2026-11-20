import PaiementGroupeDialog from '@/components/commission/PaiementGroupeDialog.vue';
import type { FicheAPayer, TresorerieFiche } from '@/types/commission';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, nextTick } from 'vue';

const SelectFactice = defineComponent({
    props: {
        modelValue: { type: null, default: null },
        options: { type: Array, default: () => [] },
        optionDisabled: { type: Function, default: null },
    },
    emits: ['update:modelValue'],
    template: '<div data-testid="select" />',
});

const InputTexteFactice = defineComponent({
    props: { modelValue: { type: String, default: '' } },
    emits: ['update:modelValue'],
    template:
        '<input data-testid="reference" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
});

const DialogFactice = defineComponent({
    template: '<div><slot /><slot name="footer" /></div>',
});

const tresorerie = (
    siteId: string,
    solde: number | null = 5_000_000,
): TresorerieFiche => ({
    site_id: siteId,
    moyens: [
        {
            key: 'mobile_money:s-orange',
            label: 'Orange Money',
            mode_paiement: 'mobile_money',
            operateur_mobile_money: 'orange_money',
            compte_tresorerie_id: 's-orange',
            reference_requise: true,
            solde_disponible: solde,
        },
    ],
    especes_disponibles: false,
    solde_especes: null,
    message: null,
});

const fiche = (
    id: string,
    nom: string,
    restant: number,
    t: TresorerieFiche = tresorerie('matoto'),
): FicheAPayer => ({
    id,
    reference: `FICHE-${id}`,
    beneficiaire_type: 'livreur',
    beneficiaire_nom: nom,
    periode_reference: 'PAY-202609-P1-LIV',
    periode_debut: '2026-09-01',
    periode_fin: '2026-09-15',
    montant_net: restant,
    montant_paye: 0,
    montant_restant: restant,
    tresorerie: t,
});

const monter = (fiches: FicheAPayer[]) =>
    mount(PaiementGroupeDialog, {
        props: { visible: false, fiches },
        global: {
            stubs: {
                Dialog: DialogFactice,
                Select: SelectFactice,
                InputText: InputTexteFactice,
            },
        },
    });

async function ouvrir(wrapper: ReturnType<typeof monter>) {
    await wrapper.setProps({ visible: true });
    await nextTick();
    // Espèces indisponibles (pas de caisse) : on choisit Orange Money.
    wrapper
        .findComponent(SelectFactice)
        .vm.$emit('update:modelValue', 'mobile_money:s-orange');
    await nextTick();
}

const confirmer = (wrapper: ReturnType<typeof monter>) =>
    wrapper.get('[data-testid="paiement-groupe-confirmer"]');

describe('PaiementGroupeDialog', () => {
    it('affiche le total des restes et exige une référence pour le Mobile Money', async () => {
        const wrapper = monter([
            fiche('a', 'Saa Fodé', 1_656_200),
            fiche('b', 'Thierno', 190_000),
        ]);
        await ouvrir(wrapper);

        expect(
            wrapper.get('[data-testid="paiement-groupe-total"]').text(),
        ).toContain('1 846 200');
        expect(confirmer(wrapper).text()).toBe('Marquer les 2 comme payés');
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
        expect(
            wrapper.get('[data-testid="paiement-groupe-references"]').text(),
        ).toContain('2 ligne(s)');

        // Référence commune : chaque ligne en hérite.
        await wrapper.get('#paiement-groupe-reference').setValue('LOT-OM');
        expect(confirmer(wrapper).attributes('disabled')).toBeUndefined();

        await confirmer(wrapper).trigger('click');
        const [payload] = wrapper.emitted('submit')![0] as [
            Record<string, unknown>,
        ];
        expect(payload).toMatchObject({
            mode_paiement: 'mobile_money',
            compte_tresorerie_id: 's-orange',
            reference_paiement: 'LOT-OM',
        });
    });

    it('accepte une référence par ligne sans référence commune', async () => {
        const wrapper = monter([fiche('a', 'Saa Fodé', 100_000)]);
        await ouvrir(wrapper);

        const ligne = wrapper
            .findAll('[data-testid="reference"]')
            .find((i) => i.attributes('aria-label')?.includes('Saa Fodé'))!;
        await ligne.setValue('OM-001');
        await confirmer(wrapper).trigger('click');

        const [payload] = wrapper.emitted('submit')![0] as [
            { references: Record<string, string> },
        ];
        expect(payload.references).toEqual({ a: 'OM-001' });
    });

    it('bloque une sélection payée par plusieurs agences', async () => {
        const wrapper = monter([
            fiche('a', 'Saa Fodé', 100_000),
            fiche('b', 'Thierno', 100_000, tresorerie('kindia')),
        ]);
        await wrapper.setProps({ visible: true });

        expect(
            wrapper.find('[data-testid="paiement-groupe-agences"]').exists(),
        ).toBe(true);
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
    });

    it('bloque si le solde du compte ne couvre pas le total', async () => {
        const wrapper = monter([
            fiche('a', 'Saa Fodé', 300_000, tresorerie('matoto', 350_000)),
            fiche('b', 'Thierno', 100_000, tresorerie('matoto', 350_000)),
        ]);
        await ouvrir(wrapper);
        await wrapper.get('#paiement-groupe-reference').setValue('LOT-OM');

        expect(
            wrapper.get('[data-testid="paiement-groupe-solde"]').text(),
        ).toContain('Solde insuffisant');
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
    });

    it('propose tous les moyens du bouton Payer, avec la référence propre à chacun', async () => {
        const banque = {
            operateur_mobile_money: null,
            compte_tresorerie_id: 's-banque',
            reference_requise: false,
            solde_disponible: 5_000_000,
        };
        const t: TresorerieFiche = {
            ...tresorerie('matoto'),
            moyens: [
                ...tresorerie('matoto').moyens,
                {
                    key: 'mobile_money:s-momo',
                    label: 'MoMo',
                    mode_paiement: 'mobile_money',
                    operateur_mobile_money: 'momo',
                    compte_tresorerie_id: 's-momo',
                    reference_requise: true,
                    solde_disponible: 5_000_000,
                },
                {
                    ...banque,
                    key: 'virement:s-banque',
                    label: 'Virement bancaire — BICIGUI',
                    mode_paiement: 'virement',
                },
                {
                    ...banque,
                    key: 'cheque:s-banque',
                    label: 'Chèque — BICIGUI',
                    mode_paiement: 'cheque',
                },
            ],
            especes_disponibles: true,
            solde_especes: 5_000_000,
        };
        const wrapper = monter([fiche('a', 'Saa Fodé', 100_000, t)]);
        await wrapper.setProps({ visible: true });
        await nextTick();

        const select = wrapper.findComponent(SelectFactice);
        expect(
            (select.props('options') as { key: string }[]).map((o) => o.key),
        ).toEqual([
            'especes',
            'mobile_money:s-orange',
            'mobile_money:s-momo',
            'virement:s-banque',
            'cheque:s-banque',
        ]);

        // Virement : aucune référence demandée, le compte bancaire choisi est envoyé.
        select.vm.$emit('update:modelValue', 'virement:s-banque');
        await nextTick();
        expect(wrapper.find('#paiement-groupe-reference').exists()).toBe(false);
        await confirmer(wrapper).trigger('click');
        expect(wrapper.emitted('submit')![0][0]).toMatchObject({
            mode_paiement: 'virement',
            compte_tresorerie_id: 's-banque',
            references: {},
        });
    });

    it('verrouille les boutons pendant l’envoi', async () => {
        const wrapper = monter([fiche('a', 'Saa Fodé', 100_000)]);
        await ouvrir(wrapper);
        await wrapper.setProps({ processing: true, progression: 40 });

        expect(confirmer(wrapper).text()).toContain('40 %');
        expect(confirmer(wrapper).attributes('disabled')).toBeDefined();
    });
});
