import FilterDrawer from '@/components/FilterDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import FilterSearchSelect from '@/components/filters/FilterSearchSelect.vue';
import { shallowMount } from '@vue/test-utils';
import Select from 'primevue/select';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const routerGet = vi.hoisted(() => vi.fn());
const auth = vi.hoisted(() => ({
    valeur: {
        roles: ['admin_entreprise'],
        voit_toutes_agences: true,
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
    usePage: () => ({
        props: { auth: auth.valeur, org_sites: [] },
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

describe('DataFilters — bouton seul', () => {
    it('garde le tiroir accessible quand Agence est le seul filtre et transmet site_ids', async () => {
        routerGet.mockClear();
        const wrapper = shallowMount(DataFilters, {
            props: {
                url: '/liste',
                fields: [],
                values: {},
                triggerOnly: true,
                sites: [
                    { id: 'cba', nom: 'Cba' },
                    { id: 'matoto', nom: 'Matoto' },
                ],
                resultCount: 0,
            },
            global: { renderStubDefaultSlot: true },
        });
        expect(wrapper.findComponent(FilterDrawer).exists()).toBe(true);
        wrapper
            .getComponent(FilterMultiSelect)
            .vm.$emit('update:modelValue', ['cba']);
        await nextTick();
        wrapper.getComponent(FilterDrawer).vm.$emit('apply');
        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { site_ids: ['cba'] },
            expect.anything(),
        );
    });

    it('verrouille le filtre Agence sur le périmètre serveur, quel que soit le nom du rôle', async () => {
        routerGet.mockClear();
        const precedent = auth.valeur;
        // Seul auth.voit_toutes_agences ouvre le filtre (ADR 0025) : un nom de rôle ne suffit pas.
        auth.valeur = {
            roles: ['admin_entreprise'],
            voit_toutes_agences: false,
            user_sites: [{ id: 'matoto', nom: 'Matoto' }],
        };
        try {
            const wrapper = shallowMount(DataFilters, {
                props: {
                    url: '/liste',
                    fields: [],
                    values: { site_ids: ['cba'] },
                    triggerOnly: true,
                    sites: [
                        { id: 'cba', nom: 'Cba' },
                        { id: 'matoto', nom: 'Matoto' },
                    ],
                    resultCount: 0,
                },
                global: { renderStubDefaultSlot: true },
            });
            const agence = wrapper.getComponent(FilterMultiSelect);
            expect(agence.props('options')).toEqual([
                { value: 'matoto', label: 'Matoto' },
            ]);
            expect(agence.props('modelValue')).toEqual(['matoto']);
            wrapper.getComponent(FilterDrawer).vm.$emit('apply');
            await nextTick();
            expect(routerGet).toHaveBeenCalledWith(
                '/liste',
                {},
                expect.anything(),
            );
        } finally {
            auth.valeur = precedent;
        }
    });

    it('ne crée pas de tiroir sans aucun filtre disponible', () => {
        const wrapper = shallowMount(DataFilters, {
            props: {
                url: '/liste',
                fields: [],
                values: {},
                triggerOnly: true,
                resultCount: 0,
            },
            global: { renderStubDefaultSlot: true },
        });
        expect(wrapper.findComponent(FilterDrawer).exists()).toBe(false);
    });

    it('déplace aussi un champ inline dans le tiroir en mode bouton seul', () => {
        const wrapper = shallowMount(DataFilters, {
            props: {
                url: '/liste',
                fields: champs,
                values: {},
                triggerOnly: true,
                resultCount: 0,
            },
            global: { renderStubDefaultSlot: true },
        });
        expect(wrapper.findComponent(FilterDrawer).exists()).toBe(true);
        expect(wrapper.find(montantMin).exists()).toBe(false);
    });
});

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

describe('DataFilters — liste avec recherche par nom (searchable)', () => {
    beforeEach(() => routerGet.mockClear());

    const caisses = [
        { value: 'c1', label: 'Caisse Moussa sidibé' },
        { value: 'c2', label: 'Caisse Agence' },
    ];

    const monterCaisse = (
        values: Record<string, unknown> = {},
        surcharge: Partial<FilterField> = {},
    ) =>
        shallowMount(DataFilters, {
            props: {
                url: '/liste',
                values,
                resultCount: 2,
                fields: [
                    {
                        key: 'caisse_id',
                        label: 'Caisse',
                        type: 'select',
                        inline: true,
                        searchable: true,
                        placeholder: 'Rechercher une caisse…',
                        options: caisses,
                        ...surcharge,
                    },
                    {
                        key: 'nature',
                        label: 'Nature',
                        type: 'select',
                        inline: true,
                        options: [
                            { value: 'inter_sites', label: 'Entre agences' },
                        ],
                    },
                ],
            },
            global: { stubs: { FilterBar: barreComplete } },
        });

    it('affiche la liste avec recherche pour un champ searchable, la liste classique pour les autres', () => {
        const wrapper = monterCaisse();
        const recherche = wrapper.findComponent(FilterSearchSelect);

        expect(wrapper.findAllComponents(FilterSearchSelect)).toHaveLength(1);
        expect(wrapper.findAllComponents(FilterMultiSelect)).toHaveLength(1);
        expect(recherche.props('options')).toEqual(caisses);
        expect(recherche.props('placeholder')).toBe('Rechercher une caisse…');
    });

    it('restitue la caisse déjà filtrée', () => {
        const wrapper = monterCaisse({ caisse_id: 'c1' });

        expect(
            wrapper.findComponent(FilterSearchSelect).props('modelValue'),
        ).toEqual(['c1']);
    });

    it('envoie l’identifiant de la caisse choisie (pas son nom) au serveur', async () => {
        const wrapper = monterCaisse();

        wrapper
            .findComponent(FilterSearchSelect)
            .vm.$emit('update:modelValue', ['c2']);
        // « Appliquer » n'est actif qu'après le rendu qui constate le changement en attente.
        await nextTick();
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/liste',
            { caisse_id: 'c2' },
            expect.objectContaining({ replace: true }),
        );
    });

    it('retire le paramètre quand le filtre est effacé', async () => {
        const wrapper = monterCaisse({ caisse_id: 'c1' });

        wrapper
            .findComponent(FilterSearchSelect)
            .vm.$emit('update:modelValue', []);
        await nextTick();
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/liste', {}, expect.anything());
    });

    it('élargit le champ avec `wide` (280 px), sinon 180 px', () => {
        const largeur = (wrapper: ReturnType<typeof monterCaisse>) =>
            wrapper
                .get('[data-testid="filter-inline-caisse_id"] > div')
                .classes();

        expect(largeur(monterCaisse())).toContain('w-[180px]');
        expect(largeur(monterCaisse({}, { wide: true }))).toContain(
            'w-[280px]',
        );
    });
});

describe('DataFilters — champ période (raccourcis résolus côté serveur)', () => {
    const periode: FilterField[] = [
        {
            key: 'periode',
            label: 'Période',
            type: 'period',
            inline: true,
            defaultValue: 'aujourd_hui',
            startKey: 'date_from',
            endKey: 'date_to',
            options: [
                { value: 'aujourd_hui', label: "Aujourd'hui" },
                { value: 'hier', label: 'Hier' },
                { value: 'personnalisee', label: 'Période personnalisée' },
            ],
        },
    ];

    const monterPeriode = (values: Record<string, unknown> = {}) =>
        shallowMount(DataFilters, {
            props: { url: '/rapport', values, fields: periode, resultCount: 0 },
            global: { stubs: { FilterBar: barreComplete } },
        });

    beforeEach(() => routerGet.mockClear());

    it('envoie le raccourci choisi, sans dates', async () => {
        const wrapper = monterPeriode({ periode: 'aujourd_hui' });

        wrapper.findComponent(Select).vm.$emit('update:modelValue', 'hier');
        await nextTick();
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/rapport',
            { periode: 'hier' },
            expect.anything(),
        );
    });

    it('affiche les deux dates pour une période personnalisée et les envoie', async () => {
        const wrapper = monterPeriode({ periode: 'aujourd_hui' });

        expect(
            wrapper
                .find('[data-testid="filter-inline-periode-debut"]')
                .exists(),
        ).toBe(false);

        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', 'personnalisee');
        await nextTick();
        await wrapper
            .get('[data-testid="filter-inline-periode-debut"]')
            .setValue('2026-09-01');
        await wrapper
            .get('[data-testid="filter-inline-periode-fin"]')
            .setValue('2026-09-15');
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/rapport',
            { date_from: '2026-09-01', date_to: '2026-09-15' },
            expect.anything(),
        );
    });

    it('ne compte pas la période par défaut comme un filtre actif', () => {
        const parDefaut = monterPeriode({ periode: 'aujourd_hui' });
        expect(parDefaut.find(reinitialiser).exists()).toBe(false);

        const hier = monterPeriode({ periode: 'hier' });
        expect(hier.find(reinitialiser).exists()).toBe(true);
    });
});

describe('DataFilters — options dépendantes (optionsFrom)', () => {
    beforeEach(() => routerGet.mockClear());

    const vehicules = [
        { value: 'fanta', label: 'FANTA — JA473', proprietaire_id: 'diallo' },
        { value: 'toyota', label: 'TOYOTA — RC125', proprietaire_id: 'diallo' },
        { value: 'moto', label: 'MOTO — XX001', proprietaire_id: 'bah' },
    ];

    const monterParc = (values: Record<string, unknown> = {}) =>
        shallowMount(DataFilters, {
            props: {
                url: '/depenses',
                values,
                resultCount: 0,
                fields: [
                    {
                        key: 'proprietaire_id',
                        label: 'Propriétaire',
                        type: 'select',
                        inline: true,
                        searchable: true,
                        options: [
                            { value: 'diallo', label: 'Mamadou DIALLO' },
                            { value: 'bah', label: 'Alpha BAH' },
                        ],
                    },
                    {
                        key: 'vehicule_ids',
                        label: 'Véhicule',
                        type: 'multi-select',
                        inline: true,
                        searchable: true,
                        optionsFrom: (v) => {
                            const p = (v.proprietaire_id as string[])?.[0];
                            return p
                                ? vehicules.filter(
                                      (x) => x.proprietaire_id === p,
                                  )
                                : vehicules;
                        },
                    },
                ],
            },
            global: { stubs: { FilterBar: barreComplete } },
        });

    const listeVehicules = (wrapper: ReturnType<typeof monterParc>) =>
        wrapper.findComponent(FilterMultiSelect);

    it('propose tous les véhicules sans propriétaire, puis seulement ceux du propriétaire choisi', async () => {
        const wrapper = monterParc();
        expect(listeVehicules(wrapper).props('options')).toHaveLength(3);
        expect(listeVehicules(wrapper).props('filter')).toBe(true);

        wrapper
            .findComponent(FilterSearchSelect)
            .vm.$emit('update:modelValue', ['diallo']);
        await nextTick();

        expect(
            listeVehicules(wrapper)
                .props('options')
                .map((o) => o.value),
        ).toEqual(['fanta', 'toyota']);
    });

    it('retire un véhicule déjà coché qui n’appartient pas au propriétaire choisi', async () => {
        const wrapper = monterParc({ vehicule_ids: ['fanta', 'moto'] });

        wrapper
            .findComponent(FilterSearchSelect)
            .vm.$emit('update:modelValue', ['diallo']);
        await nextTick();
        await nextTick();
        await wrapper.get(appliquer).trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/depenses',
            { proprietaire_id: 'diallo', vehicule_ids: ['fanta'] },
            expect.anything(),
        );
    });
});
