import InfoTooltip from '@/components/InfoTooltip.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const monter = () =>
    mount(InfoTooltip, {
        attachTo: document.body,
        props: { label: 'À propos' },
        slots: { default: 'Explication complète' },
    });

const bulle = () => document.querySelector('[data-slot="tooltip-content"]');

const declencher = async (
    wrapper: ReturnType<typeof monter>,
    evenement: string,
) => {
    await wrapper.find('button').trigger(evenement);
    await nextTick();
};

describe('InfoTooltip', () => {
    let wrapper: ReturnType<typeof monter>;

    beforeEach(() => {
        // Absent de jsdom, requis pour positionner la bulle.
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe() {}
                unobserve() {}
                disconnect() {}
            },
        );
    });

    afterEach(() => {
        wrapper.unmount();
        vi.unstubAllGlobals();
    });

    it('reste ouverte après le premier appui, qui donne le focus juste avant le clic (Android)', async () => {
        wrapper = monter();

        await declencher(wrapper, 'focus');
        await declencher(wrapper, 'click');

        expect(bulle()?.textContent).toContain('Explication complète');
    });

    it('se referme au deuxième appui', async () => {
        wrapper = monter();

        await declencher(wrapper, 'focus');
        await declencher(wrapper, 'click');
        await declencher(wrapper, 'click');

        expect(wrapper.find('button').attributes('data-state')).toBe('closed');
    });
});
