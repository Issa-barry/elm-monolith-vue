import ListPageActions from '@/components/ListPageActions.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ListPageActions', () => {
    it("rend les actions dans l'ordre Exporter → Importer → Filtres → Nouveau même si les slots sont déclarés dans le désordre", () => {
        const wrapper = mount(ListPageActions, {
            slots: {
                // Déclarés volontairement dans le désordre : primary, export,
                // filters, import — l'ordre de sortie doit rester fixe.
                primary: '<button data-testid="primary">Nouveau</button>',
                export: '<button data-testid="export">Exporter</button>',
                filters: '<button data-testid="filters">Filtres</button>',
                import: '<button data-testid="import">Importer</button>',
            },
        });

        const order = wrapper
            .findAll('button')
            .map((btn) => btn.attributes('data-testid'));

        expect(order).toEqual(['export', 'import', 'filters', 'primary']);
    });

    it("n'affiche aucune trace des actions non fournies", () => {
        const wrapper = mount(ListPageActions, {
            slots: {
                primary: '<button data-testid="primary">Nouveau</button>',
            },
        });

        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(wrapper.find('[data-testid="export"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="import"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="filters"]').exists()).toBe(false);
    });

    it('ne rend rien de plus que le conteneur quand aucun slot n’est fourni (liste sans action)', () => {
        const wrapper = mount(ListPageActions);

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.element.children).toHaveLength(0);
    });

    it('laisse traverser le contenu du slot filtres tel quel (ex: badge de comptage de filtres actifs)', () => {
        // ListPageActions ne connaît rien du comptage de filtres — c'est
        // DataFilters/FilterDrawer qui le calcule. On vérifie seulement que
        // le contenu passé au slot #filters (bouton + badge) traverse sans
        // être altéré ou réordonné par le wrapper.
        const wrapper = mount(ListPageActions, {
            slots: {
                filters:
                    '<button data-testid="filters-trigger">Filtres<span data-testid="filters-badge">2</span></button>',
                primary: '<button data-testid="primary">Nouveau</button>',
            },
        });

        expect(wrapper.find('[data-testid="filters-badge"]').text()).toBe('2');
        const order = wrapper
            .findAll('button')
            .map((btn) => btn.attributes('data-testid'));
        expect(order).toEqual(['filters-trigger', 'primary']);
    });
});
