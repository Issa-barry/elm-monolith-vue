import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function monter() {
    return mount(PhoneCountryInput, {
        props: { rememberChoice: false },
        global: { stubs: { Select: true } },
    });
}

describe('PhoneCountryInput', () => {
    it('ne pose pas de maxlength, qui couperait un numéro collé avec son 0 initial', () => {
        expect(monter().get('input').attributes('maxlength')).toBeUndefined();
    });

    it('accepte un numéro collé avec son 0 initial et retire ce 0 du numéro complet', async () => {
        const wrapper = monter();

        await wrapper.get('input').setValue('0612345678');

        expect(wrapper.emitted('update:valid')?.at(-1)).toEqual([true]);
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '+224612345678',
        ]);
    });

    it('limite la saisie à la longueur nationale du pays', async () => {
        const wrapper = monter();
        const input = wrapper.get('input');

        await input.setValue('61234567899');

        expect((input.element as HTMLInputElement).value).toBe('612345678');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '+224612345678',
        ]);
    });

    it('signale un numéro trop court comme invalide', async () => {
        const wrapper = monter();

        await wrapper.get('input').setValue('612');

        expect(wrapper.emitted('update:valid')?.at(-1)).toEqual([false]);
    });
});
