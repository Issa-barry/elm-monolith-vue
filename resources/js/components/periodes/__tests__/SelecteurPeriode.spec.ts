import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import type { PeriodeFiltre } from '@/types/periode';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { get: routerGet } }));

// Liste : rend le libellé de son slot #value (comme PrimeVue) et émet `change` à chaque choix.
const ChoixStub = defineComponent({
    name: 'ChoixStub',
    props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'loading'],
    emits: ['update:modelValue', 'change'],
    setup:
        (props, { slots }) =>
        () =>
            h(
                'div',
                { 'data-testid': 'libelle-liste' },
                slots.value?.({ value: props.modelValue }),
            ),
});

// Fenêtre contextuelle : contenu rendu seulement une fois ouverte, comme PrimeVue.
const FenetreStub = defineComponent({
    name: 'FenetreStub',
    emits: ['show', 'hide'],
    setup: (_props, { slots, emit, expose }) => {
        const ouverte = ref(false);
        expose({
            show: () => {
                ouverte.value = true;
                emit('show');
            },
            hide: () => {
                ouverte.value = false;
                emit('hide');
            },
        });
        return () =>
            ouverte.value
                ? h('div', { 'data-testid': 'fenetre' }, slots.default?.())
                : null;
    },
});

const BoutonStub = defineComponent({
    name: 'BoutonStub',
    props: ['label', 'loading', 'disabled', 'type'],
    setup: (props) => () =>
        h(
            'button',
            { type: props.type, disabled: props.disabled },
            props.label,
        ),
});

const stubs = { Select: ChoixStub, Popover: FenetreStub, Button: BoutonStub };

const options = [
    { value: 'aujourd_hui', label: "Aujourd'hui" },
    { value: 'cette_semaine', label: 'Cette semaine' },
    { value: 'ce_mois', label: 'Ce mois' },
    { value: 'tout', label: 'Toutes les périodes' },
    { value: 'personnalisee', label: 'Période personnalisée' },
];

const ceMois: PeriodeFiltre = {
    cle: 'ce_mois',
    date_debut: '2026-11-01',
    date_fin: '2026-11-30',
    options,
    libelle: 'Ce mois (du 01/11/2026 au 30/11/2026)',
};

const perso: PeriodeFiltre = {
    cle: 'personnalisee',
    date_debut: '2026-10-01',
    date_fin: '2026-11-23',
    options,
    libelle: 'Du 01/10/2026 au 23/11/2026',
};

const URL_PAGE = '/backoffice/tableau-de-bord/commissions';

function monter(
    periode: PeriodeFiltre = ceMois,
    parametres?: Record<string, string>,
) {
    return mount(SelecteurPeriode, {
        props: { url: URL_PAGE, periode, parametres },
        global: { stubs },
        attachTo: document.body,
    });
}

async function choisir(wrapper: ReturnType<typeof monter>, valeur: string) {
    const liste = wrapper.findComponent(ChoixStub);
    await liste.vm.$emit('update:modelValue', valeur);
    await liste.vm.$emit('change', {
        originalEvent: new Event('click'),
        value: valeur,
    });
    // La fenêtre s'ouvre après le clic sur l'option.
    await new Promise((r) => setTimeout(r, 0));
    await nextTick();
}

const fenetre = (wrapper: ReturnType<typeof monter>) =>
    wrapper.find('[data-testid="fenetre"]');

describe('SelecteurPeriode', () => {
    beforeEach(() => routerGet.mockReset());

    it('propose les choix du serveur et affiche la période active', () => {
        const wrapper = monter();
        const liste = wrapper.findComponent(ChoixStub);

        expect(liste.props('options')).toEqual(options);
        expect(liste.props('modelValue')).toBe('ce_mois');
        expect(wrapper.find('[data-testid="periode-choix"]').text()).toBe(
            'Ce mois',
        );
    });

    it('une période prédéfinie recharge la page, sans fenêtre de dates', async () => {
        const wrapper = monter();
        await choisir(wrapper, 'cette_semaine');

        expect(routerGet).toHaveBeenCalledWith(
            URL_PAGE,
            { periode: 'cette_semaine' },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
        expect(fenetre(wrapper).exists()).toBe(false);
    });

    it('la ligne de filtres ne contient jamais les dates : elles sont dans la fenêtre', () => {
        const wrapper = monter(perso);

        expect(
            wrapper.find('[data-testid="periode-date-debut"]').exists(),
        ).toBe(false);
        expect(wrapper.find('[data-testid="periode-appliquer"]').exists()).toBe(
            false,
        );
    });

    it('« Période personnalisée » ouvre la fenêtre, dates préremplies, sans recharger', async () => {
        const wrapper = monter();
        await choisir(wrapper, 'personnalisee');

        expect(routerGet).not.toHaveBeenCalled();
        expect(fenetre(wrapper).exists()).toBe(true);
        const debut = wrapper.find<HTMLInputElement>(
            '[data-testid="periode-date-debut"]',
        );
        expect(debut.element.value).toBe('2026-11-01');
        expect(debut.attributes('type')).toBe('date');
        expect(
            wrapper.find<HTMLInputElement>('[data-testid="periode-date-fin"]')
                .element.value,
        ).toBe('2026-11-30');
    });

    it('applique les dates (date_debut et date_fin, jamais de clé) puis ferme la fenêtre', async () => {
        routerGet.mockImplementationOnce(
            (_u: string, _p: unknown, o: { onSuccess: () => void }) =>
                o.onSuccess(),
        );
        const wrapper = monter(ceMois, { site_id: 'site-matoto' });
        await choisir(wrapper, 'personnalisee');
        await wrapper
            .find('[data-testid="periode-date-debut"]')
            .setValue('2026-10-01');
        await wrapper
            .find('[data-testid="periode-date-fin"]')
            .setValue('2026-11-23');
        await wrapper.find('form').trigger('submit');

        expect(routerGet).toHaveBeenCalledWith(
            URL_PAGE,
            {
                site_id: 'site-matoto',
                date_debut: '2026-10-01',
                date_fin: '2026-11-23',
            },
            expect.any(Object),
        );
        expect(fenetre(wrapper).exists()).toBe(false);
    });

    it('fin avant le début : message dans la fenêtre, Appliquer bloqué, fenêtre ouverte', async () => {
        const wrapper = monter();
        await choisir(wrapper, 'personnalisee');
        await wrapper
            .find('[data-testid="periode-date-debut"]')
            .setValue('2026-11-23');
        await wrapper
            .find('[data-testid="periode-date-fin"]')
            .setValue('2026-11-01');

        expect(
            fenetre(wrapper).find('[data-testid="periode-erreur"]').text(),
        ).toBe(
            'La date de fin doit être postérieure ou égale à la date de début.',
        );
        expect(
            wrapper.find('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();
        await wrapper.find('form').trigger('submit');
        expect(routerGet).not.toHaveBeenCalled();
        expect(fenetre(wrapper).exists()).toBe(true);
    });

    it('exige les deux dates', async () => {
        const wrapper = monter();
        await choisir(wrapper, 'personnalisee');
        await wrapper.find('[data-testid="periode-date-fin"]').setValue('');

        expect(wrapper.find('[data-testid="periode-erreur"]').text()).toBe(
            'Renseignez la date de fin.',
        );
        await wrapper.find('form').trigger('submit');
        expect(routerGet).not.toHaveBeenCalled();
    });

    it('refus du serveur : message dans la fenêtre, qui reste ouverte avec la saisie', async () => {
        routerGet.mockImplementationOnce(
            (
                _u: string,
                _p: unknown,
                o: { onError: (e: Record<string, string>) => void },
            ) => o.onError({ date_fin: 'La date de fin est invalide.' }),
        );
        const wrapper = monter();
        await choisir(wrapper, 'personnalisee');
        await wrapper
            .find('[data-testid="periode-date-debut"]')
            .setValue('2026-10-01');
        await wrapper.find('form').trigger('submit');
        await nextTick();

        expect(fenetre(wrapper).exists()).toBe(true);
        expect(wrapper.find('[data-testid="periode-erreur"]').text()).toBe(
            'La date de fin est invalide.',
        );
        expect(
            wrapper.find<HTMLInputElement>('[data-testid="periode-date-debut"]')
                .element.value,
        ).toBe('2026-10-01');
    });

    it('fermée sans appliquer : la liste revient à la période affichée', async () => {
        const wrapper = monter();
        await choisir(wrapper, 'personnalisee');
        await wrapper.findComponent(FenetreStub).vm.$emit('hide');
        await nextTick();

        expect(wrapper.findComponent(ChoixStub).props('modelValue')).toBe(
            'ce_mois',
        );
        expect(routerGet).not.toHaveBeenCalled();
    });

    it('une période personnalisée appliquée s’affiche « 01/10/2026 – 23/11/2026 »', () => {
        const wrapper = monter(perso);

        expect(wrapper.findComponent(ChoixStub).props('modelValue')).toBe(
            'personnalisee',
        );
        expect(wrapper.find('[data-testid="periode-choix"]').text()).toBe(
            '01/10/2026 – 23/11/2026',
        );
    });

    it('reprend la période renvoyée par le serveur', async () => {
        const wrapper = monter();
        await wrapper.setProps({ periode: perso });

        expect(wrapper.findComponent(ChoixStub).props('modelValue')).toBe(
            'personnalisee',
        );
    });
});
