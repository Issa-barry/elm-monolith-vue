import CommissionIndexLayout from '@/components/commission/CommissionIndexLayout.vue';
import CommissionIndexSummaryCards from '@/components/commission/CommissionIndexSummaryCards.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const filterFields: FilterField[] = [
    {
        key: 'periode',
        label: 'Période',
        type: 'select',
        options: [{ value: '2026-P1', label: 'Période 1' }],
    },
    {
        key: 'categorie_id',
        label: 'Catégorie',
        type: 'select',
        options: [{ value: 'cat-1', label: 'Bouteilles' }],
    },
];

function mountLayout(resultCount = 2) {
    return shallowMount(CommissionIndexLayout, {
        props: {
            title: 'Commissions des sites',
            entityCount: resultCount,
            entityLabel: 'site',
            periodLabel: 'Août 2026',
            periodStatus: { status: 'ouverte', label: 'Ouverte' },
            filterUrl: '/backoffice/comptabilite/commissions/sites',
            filterValues: {
                site_ids: ['site-1'],
                periode: '2026-P1',
                categorie_id: 'cat-1',
            },
            filterFields,
            sites: [{ id: 'site-1', nom: 'Matoto' }],
            summary: {
                generated: 3_000_000,
                expenses: 50_000,
                netValidated: 2_950_000,
                remaining: 1_000_000,
                paid: 1_950_000,
            },
            tableTitle: 'Détail par site',
            resultCount,
        },
        slots: {
            default: '<table data-testid="domain-table"><tbody /></table>',
        },
        global: {
            renderStubDefaultSlot: true,
        },
    });
}

describe('CommissionIndexLayout', () => {
    it('affiche le contexte commun et transmet les filtres propres à la page', () => {
        const wrapper = mountLayout();

        expect(wrapper.get('h1').text()).toBe('Commissions des sites');
        expect(wrapper.text()).toContain('2 sites');
        expect(wrapper.text()).toContain('Août 2026');
        expect(wrapper.text()).toContain('Détail par site');
        expect(wrapper.text()).toContain('2 résultats');

        const filters = wrapper.getComponent(DataFilters);
        expect(filters.props('url')).toBe(
            '/backoffice/comptabilite/commissions/sites',
        );
        expect(filters.props('fields')).toEqual(filterFields);
        expect(filters.props('values')).toMatchObject({
            site_ids: ['site-1'],
            categorie_id: 'cat-1',
        });
        expect(filters.props('triggerOnly')).toBe(true);

        expect(
            wrapper.getComponent(CommissionIndexSummaryCards).props('summary'),
        ).toMatchObject({ generated: 3_000_000, expenses: 50_000 });
        expect(wrapper.getComponent(StatusDot).props()).toMatchObject({
            status: 'ouverte',
            label: 'Ouverte',
        });
        expect(
            wrapper.find('[data-testid="commission-table-scroll"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-testid="domain-table"]').exists()).toBe(
            true,
        );
    });

    it('émet les deux actions du menu export', async () => {
        const wrapper = mountLayout();
        const items = wrapper.findAllComponents(DropdownMenuItem);

        expect(items).toHaveLength(2);

        await items[0].trigger('click');
        await items[1].trigger('click');

        expect(wrapper.emitted('exportExcel')).toHaveLength(1);
        expect(wrapper.emitted('exportPdf')).toHaveLength(1);
    });

    it('affiche un état vide commun sans rendre le tableau métier', () => {
        const wrapper = mountLayout(0);

        expect(
            wrapper.find('[data-testid="commission-empty-state"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-testid="commission-table-scroll"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).toContain('Aucune commission trouvée');
    });
});
