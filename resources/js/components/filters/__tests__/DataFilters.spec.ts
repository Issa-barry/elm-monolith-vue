import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { shallowMount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
    usePage: () => ({
        props: { auth: { roles: ['admin_entreprise'] }, org_sites: [] },
    }),
}));

// FilterBar rend ses deux slots (champs + actions) : sans ça, le stub de shallowMount
// n'afficherait pas le bouton « Appliquer », qui vit dans le slot #actions.
const barreComplete = {
    template: '<div><slot /><slot name="actions" /></div>',
};

const montantMin = '[data-testid="filter-inline-montant_min"]';
const montantMax = '[data-testid="filter-inline-montant_max"]';
const appliquer = '[data-testid="filters-search"]';
const reinitialiser = '[data-testid="filters-reset"]';

const champs: FilterField[] = [
    {
        key: 'montant_min',
        label: 'Montant min',
        type: 'number',
        inline: true,
        placeholder: '0',
    },
    {
        key: 'montant_max',
        label: 'Montant max',
        type: 'number',
        inline: true,
        placeholder: '0',
    },
];

const monter = (values: Record<string, unknown> = {}) =>
    shallowMount(DataFilters, {
        props: { url: '/liste', values, fields: champs, resultCount: 3 },
        global: { stubs: { FilterBar: barreComplete } },
    });

const valeur = (wrapper: ReturnType<typeof monter>, selecteur: string) =>
    (wrapper.get(selecteur).element as HTMLInputElement).value;

describe('DataFilters — champ numérique inline', () => {
    beforeEach(() => routerGet.mockClear());

    it('affiche un champ numérique par filtre, directement dans la barre, avec son libellé', () => {
        const wrapper = monter();
        const min = wrapper.get(montantMin);

        expect(min.attributes('type')).toBe('number');
        expect(min.attributes('placeholder')).toBe('0');
        expect(wrapper.text()).toContain('Montant min');
        expect(wrapper.text()).toContain('Montant max');
    });

    it('restitue la valeur déjà filtrée', () => {
        const wrapper = monter({ montant_min: '200000' });

        expect(valeur(wrapper, montantMin)).toBe('200000');
        expect(valeur(wrapper, montantMax)).toBe('');
    });

    it('n’active « Appliquer » qu’une fois une valeur saisie', async () => {
        const wrapper = monter();

        expect(wrapper.get(appliquer).attributes('disabled')).toBeDefined();

        await wrapper.get(montantMin).setValue('500000');

        expect(wrapper.get(appliquer).attributes('disabled')).toBeUndefined();
    });

    it('envoie la borne saisie au clic sur Appliquer et omet celle laissée vide', async () => {
        const wrapper = monter();

        await wrapper.get(montantMin).setValue('500000');
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { montant_min: 500000 },
            expect.objectContaining({ replace: true }),
        );
    });

    it('envoie les deux bornes ensemble', async () => {
        const wrapper = monter();

        await wrapper.get(montantMin).setValue('200000');
        await wrapper.get(montantMax).setValue('900000');
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { montant_min: 200000, montant_max: 900000 },
            expect.anything(),
        );
    });

    it('applique aussi avec la touche Entrée', async () => {
        const wrapper = monter();

        await wrapper.get(montantMax).setValue('900000');
        await wrapper.get(montantMax).trigger('keydown.enter');

        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { montant_max: 900000 },
            expect.anything(),
        );
    });

    it('vide les champs et recharge sans paramètre au clic sur Réinitialiser', async () => {
        const wrapper = monter({ montant_min: '200000' });

        await wrapper.get(reinitialiser).trigger('click');

        expect(valeur(wrapper, montantMin)).toBe('');
        expect(routerGet).toHaveBeenCalledWith('/liste', {}, expect.anything());
    });
});

describe('DataFilters — bouton « Filtres » déplacé hors de la barre (triggerTarget)', () => {
    // Tiroir factice : un bouton qui affiche le titre et le nombre de filtres actifs, et applique au clic.
    const tiroir = {
        props: ['title', 'activeCount'],
        emits: ['apply', 'reset'],
        template:
            '<button data-testid="filters-trigger" @click="$emit(\'apply\')">{{ title }} {{ activeCount }}</button>',
    };
    const bouton = '[data-testid="filters-trigger"]';

    const champsMixtes: FilterField[] = [
        { key: 'search', label: 'Référence', type: 'text', inline: true },
        {
            key: 'statut',
            label: 'Statut',
            type: 'select',
            options: [{ value: 'envoye', label: 'Envoyé' }],
        },
        { key: 'montant_min', label: 'Montant min', type: 'number' },
    ];

    let hote: HTMLElement;
    let wrapper: ReturnType<typeof monterMixte>;

    const monterMixte = (
        values: Record<string, unknown> = {},
        triggerTarget: HTMLElement | null = hote,
    ) =>
        shallowMount(DataFilters, {
            props: {
                url: '/liste',
                values,
                fields: champsMixtes,
                resultCount: 3,
                triggerTarget,
            },
            global: {
                // shallowMount stubbe aussi Teleport : on veut le vrai, c'est lui qu'on teste.
                stubs: {
                    FilterBar: barreComplete,
                    FilterDrawer: tiroir,
                    teleport: false,
                },
            },
            attachTo: document.body,
        });

    beforeEach(() => {
        routerGet.mockClear();
        hote = document.createElement('div');
        document.body.appendChild(hote);
    });

    afterEach(() => {
        wrapper.unmount();
        hote.remove();
    });

    it('rend le bouton dans l’emplacement fourni, et plus dans la barre', () => {
        wrapper = monterMixte();

        expect(hote.querySelector(bouton)).not.toBeNull();
        expect(wrapper.element.querySelector(bouton)).toBeNull();
    });

    it('laisse les champs inline dans la barre et le reste dans le tiroir', () => {
        wrapper = monterMixte();

        expect(
            wrapper.find('[data-testid="filter-inline-search"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-testid="filter-inline-statut"]').exists(),
        ).toBe(false);
        expect(wrapper.find(montantMin).exists()).toBe(false);
    });

    it('garde le bouton dans la barre quand aucun emplacement n’est fourni', () => {
        wrapper = monterMixte({}, null);

        expect(wrapper.element.querySelector(bouton)).not.toBeNull();
        expect(hote.children).toHaveLength(0);
    });

    it('suit l’emplacement quand il n’existe qu’après le premier rendu (ref posé au montage de la page)', async () => {
        wrapper = monterMixte({}, null);

        await wrapper.setProps({ triggerTarget: hote });

        expect(hote.querySelector(bouton)).not.toBeNull();
        expect(wrapper.element.querySelector(bouton)).toBeNull();
    });

    it('ne compte dans le badge que les filtres du tiroir, pas ceux de la barre', () => {
        wrapper = monterMixte({
            search: 'MVT-1',
            statut: 'envoye',
            montant_min: 5000,
        });

        expect(hote.querySelector(bouton)?.textContent?.trim()).toBe(
            'Filtres 2',
        );
    });

    it('applique depuis le tiroir avec les champs de la barre : un seul état de filtres', async () => {
        wrapper = monterMixte({
            search: 'MVT-1',
            statut: 'envoye',
            montant_min: 5000,
        });

        (hote.querySelector(bouton) as HTMLElement).click();

        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { search: 'MVT-1', statut: 'envoye', montant_min: 5000 },
            expect.objectContaining({ replace: true }),
        );
    });
});
