import DateRaccourcis from '@/components/Depenses/DateRaccourcis.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 9, 1, 10, 0, 0));
});

afterEach(() => vi.useRealTimers());

const boutons = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('button');

describe('DateRaccourcis', () => {
    it("« Aujourd'hui » et « Hier » posent une date précise, y compris au changement de mois", async () => {
        const wrapper = mount(DateRaccourcis, {
            props: { modelValue: '2026-09-15' },
        });

        await boutons(wrapper)[0].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '2026-10-01',
        ]);

        await boutons(wrapper)[1].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '2026-09-30',
        ]);
    });

    it('signale le raccourci correspondant à la date choisie', () => {
        const wrapper = mount(DateRaccourcis, {
            props: { modelValue: '2026-09-30' },
        });

        expect(boutons(wrapper)[0].attributes('aria-pressed')).toBe('false');
        expect(boutons(wrapper)[1].attributes('aria-pressed')).toBe('true');
    });

    it('ne propose aucun raccourci de période', () => {
        const wrapper = mount(DateRaccourcis, {
            props: { modelValue: '2026-10-01' },
        });

        expect(boutons(wrapper).map((b) => b.text())).toEqual([
            "Aujourd'hui",
            'Hier',
        ]);
    });
});
