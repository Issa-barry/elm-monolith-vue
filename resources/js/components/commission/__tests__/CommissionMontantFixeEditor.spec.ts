import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { describe, expect, it } from 'vitest';

const membres: CommissionMontantFixeMembre[] = [
    { id: 'chauffeur', label: 'Chauffeur — Mamadou SY', montant_unitaire: 500 },
    {
        id: 'convoyeur',
        label: 'Convoyeur — Ibrahima BAH',
        montant_unitaire: 300,
    },
];

function monter(props: Record<string, unknown>) {
    return mount(CommissionMontantFixeEditor, {
        props: { modelValue: membres, enveloppeUnitaire: 1000, ...props },
        global: { plugins: [PrimeVue] },
    });
}

function etat(wrapper: ReturnType<typeof monter>): string {
    return wrapper.get('[data-testid="partage-livreur-etat"]').text();
}

describe('CommissionMontantFixeEditor — part du parrain (ADR 0035)', () => {
    it("sans parrain, n'affiche aucune ligne parrain", () => {
        const wrapper = monter({ parrain: null });

        expect(
            wrapper.find('[data-testid="partage-parrain-montant"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).not.toContain('Parrain du véhicule');
        expect(etat(wrapper)).toBe('Reste à répartir');
        expect(wrapper.text()).toContain('200 GNF');
    });

    it('compte la part du parrain dans le total réparti', () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 200,
        });

        expect(wrapper.text()).toContain('Moussa SIDIBÉ');
        expect(etat(wrapper)).toBe('Répartition équilibrée');
        expect(wrapper.vm.valide).toBe(true);
    });

    it('signale un dépassement du barème avec son montant', () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 350,
        });

        expect(etat(wrapper)).toContain('Dépassement du barème');
        expect(wrapper.text()).toContain('150 GNF');
        expect(wrapper.vm.valide).toBe(false);
    });

    it('ajuste l’équipe au prorata de ses parts, la part du parrain déduite', async () => {
        const wrapper = monter({
            modelValue: [
                { id: 'chauffeur', label: 'Chauffeur', montant_unitaire: 600 },
                { id: 'convoyeur', label: 'Convoyeur', montant_unitaire: 400 },
            ],
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 200,
        });

        await wrapper
            .get('[data-testid="partage-ajuster-prorata"]')
            .trigger('click');

        const emis = wrapper
            .emitted('update:modelValue')
            ?.at(-1)?.[0] as CommissionMontantFixeMembre[];
        expect(emis.map((m) => m.montant_unitaire)).toEqual([480, 320]);
    });

    it('met à jour la part du parrain pendant la frappe', async () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 0,
        });

        const input = wrapper
            .findAllComponents({ name: 'InputNumber' })
            .at(-1)!;
        input.vm.$emit('input', { value: 200 });

        expect(wrapper.emitted('update:partParrain')?.at(-1)).toEqual([200]);
    });
});
