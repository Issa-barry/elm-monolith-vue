import MontantInput from '@/components/MontantInput.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function monter(props: Record<string, unknown> = {}) {
    return mount(MontantInput, {
        props,
        attrs: { id: 'salaire', 'data-testid': 'montant' },
        attachTo: document.body,
    });
}

describe('MontantInput', () => {
    it('affiche la valeur initiale avec séparateurs de milliers', () => {
        const wrapper = monter({ modelValue: '2500000.00' });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '2 500 000',
        );
    });

    it('formate pendant la frappe et émet un nombre sans séparateur', async () => {
        const wrapper = monter({ modelValue: null });
        const input = wrapper.get('input');

        await input.setValue('750000');

        expect((input.element as HTMLInputElement).value).toBe('750 000');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([750000]);
    });

    it('accepte un montant collé déjà formaté', async () => {
        const wrapper = monter();
        await wrapper.get('input').setValue('1 250 000 GNF');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([1250000]);
    });

    it('émet null quand le champ est effacé', async () => {
        const wrapper = monter({ modelValue: 5000 });
        await wrapper.get('input').setValue('');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null]);
    });

    it('accepte les décimales seulement si le champ les autorise', async () => {
        const entier = monter();
        await entier.get('input').setValue('1500,75');
        expect(entier.emitted('update:modelValue')?.at(-1)).toEqual([1500]);

        const decimal = monter({ decimales: 2 });
        await decimal.get('input').setValue('1500,75');
        expect(decimal.emitted('update:modelValue')?.at(-1)).toEqual([1500.75]);
        expect((decimal.get('input').element as HTMLInputElement).value).toBe(
            '1 500,75',
        );
    });

    it('réaffiche une valeur changée par le formulaire', async () => {
        const wrapper = monter({ modelValue: 1000 });
        await wrapper.setProps({ modelValue: 150000 });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '150 000',
        );

        await wrapper.setProps({ modelValue: null });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '',
        );
    });

    it('transmet les attributs au champ et signale une erreur', () => {
        const wrapper = monter({ invalid: true, unite: 'GNF' });
        const input = wrapper.get('input');

        expect(input.attributes('id')).toBe('salaire');
        expect(input.attributes('data-testid')).toBe('montant');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('inputmode')).toBe('numeric');
        expect(wrapper.text()).toContain('GNF');
    });
});
