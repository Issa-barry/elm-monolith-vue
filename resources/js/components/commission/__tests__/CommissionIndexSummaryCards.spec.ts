import CommissionIndexSummaryCards from '@/components/commission/CommissionIndexSummaryCards.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('CommissionIndexSummaryCards', () => {
    it('présente toujours les quatre indicateurs avec un format GNF lisible', () => {
        const wrapper = shallowMount(CommissionIndexSummaryCards, {
            props: {
                summary: {
                    generated: 3_084_000,
                    expenses: 70_000,
                    netValidated: 3_014_000,
                    remaining: 1_014_000,
                    paid: 2_000_000,
                },
            },
            global: {
                renderStubDefaultSlot: true,
            },
        });

        expect(
            wrapper.findAll(
                '[data-testid^="commission-summary-"]:not([data-testid="commission-summary-cards"])',
            ),
        ).toHaveLength(4);
        expect(wrapper.text()).toContain('Commissions générées');
        expect(wrapper.text()).toContain('3 084 000 GNF');
        expect(wrapper.text()).toContain('-70 000 GNF');
        expect(wrapper.text()).toContain('3 014 000 GNF');
        expect(wrapper.text()).toContain('1 014 000 GNF');
        expect(wrapper.text()).toContain('Déjà payé : 2 000 000 GNF');
    });

    it('fournit une définition accessible pour chaque indicateur', () => {
        const wrapper = shallowMount(CommissionIndexSummaryCards, {
            props: {
                summary: {
                    generated: 0,
                    expenses: 0,
                    netValidated: 0,
                    remaining: 0,
                },
            },
            global: {
                renderStubDefaultSlot: true,
            },
        });

        const definitions = wrapper.findAll('button[aria-label^="Définition"]');
        expect(definitions).toHaveLength(4);
        expect(
            definitions.map((button) => button.attributes('aria-label')),
        ).toEqual(
            expect.arrayContaining([
                'Définition des commissions générées',
                'Définition des dépenses',
                'Définition du net validé',
                'Définition du reste à payer',
            ]),
        );
    });
});
