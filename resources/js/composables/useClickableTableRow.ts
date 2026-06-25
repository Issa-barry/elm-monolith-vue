import { router } from '@inertiajs/vue3';

/**
 * Équivalent de ClickableTableRow.vue pour les pages utilisant PrimeVue
 * <DataTable> (qui génère ses propres <tr>, donc ne peut pas se faire
 * envelopper par un composant <tr>). Même règle d'ignore : un clic ou une
 * touche Entrée/Espace partant d'un élément interactif de la ligne ne
 * déclenche pas la navigation.
 *
 * Usage :
 *   const { onRowClick, bodyRowPt } = useClickableTableRow<Row>(
 *       (row) => `/ventes/${row.id}`,
 *   );
 *
 *   <DataTable @row-click="onRowClick" :pt="{ bodyRow: bodyRowPt }">
 */

const IGNORE_SELECTOR =
    'button, a, input, select, textarea, [role="button"], [data-no-row-click]';

function isInteractiveTarget(target: EventTarget | null): boolean {
    return target instanceof Element && !!target.closest(IGNORE_SELECTOR);
}

interface DataTableRowClickEvent<T> {
    originalEvent: Event;
    data: T;
    index: number;
}

interface BodyRowPtOptions<T> {
    props: { rowData: T };
}

export function useClickableTableRow<T = Record<string, unknown>>(
    hrefFor: (row: T) => string | null | undefined,
) {
    function navigate(row: T) {
        const href = hrefFor(row);
        if (href) router.visit(href);
    }

    function onRowClick(event: DataTableRowClickEvent<T>) {
        if (isInteractiveTarget((event.originalEvent as MouseEvent).target))
            return;
        navigate(event.data);
    }

    function handleKeydown(event: KeyboardEvent, row: T) {
        if (isInteractiveTarget(event.target)) return;
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        navigate(row);
    }

    // Passthrough du <tr> de chaque ligne : focus clavier + role="link",
    // sans dépendre de selectionMode (qui entrerait en conflit avec une
    // éventuelle sélection multiple déjà utilisée par la page).
    function bodyRowPt(options: BodyRowPtOptions<T>) {
        const row = options.props.rowData;
        if (!hrefFor(row)) return {};

        return {
            tabindex: 0,
            role: 'link',
            class: 'cursor-pointer hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none',
            onKeydown: (event: KeyboardEvent) => handleKeydown(event, row),
        };
    }

    return { onRowClick, bodyRowPt };
}
