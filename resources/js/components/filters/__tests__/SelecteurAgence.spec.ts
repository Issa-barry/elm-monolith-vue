import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import { parametresPeriode } from '@/lib/periodes';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { get: routerGet } }));

const ChoixStub = defineComponent({
    name: 'ChoixStub',
    props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'loading'],
    emits: ['update:modelValue'],
    setup: (props) => () => h('div', { 'data-choix': props.modelValue }),
});

const agences = [
    { value: 'site-coyah', label: 'Coyah' },
    { value: 'site-matoto', label: 'Matoto' },
];
const URL_PAGE = '/backoffice/tableau-de-bord/commissions';

function monter(agence: string | null = null) {
    return mount(SelecteurAgence, {
        props: {
            url: URL_PAGE,
            agences,
            agence,
            parametres: { date_debut: '2026-10-01', date_fin: '2026-11-23' },
        },
        global: { stubs: { Select: ChoixStub } },
    });
}

describe('SelecteurAgence', () => {
    beforeEach(() => routerGet.mockClear());

    it('propose « Toutes les agences » puis les agences du périmètre, toutes par défaut', () => {
        const choix = monter().findComponent(ChoixStub);

        expect(choix.props('options')).toEqual([
            { value: 'toutes', label: 'Toutes les agences' },
            ...agences,
        ]);
        expect(choix.props('modelValue')).toBe('toutes');
    });

    it('recharge avec l’agence choisie en conservant la période', async () => {
        await monter()
            .findComponent(ChoixStub)
            .vm.$emit('update:modelValue', 'site-matoto');

        expect(routerGet).toHaveBeenCalledWith(
            URL_PAGE,
            {
                date_debut: '2026-10-01',
                date_fin: '2026-11-23',
                site_id: 'site-matoto',
            },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('« Toutes les agences » retire site_id', async () => {
        await monter('site-matoto')
            .findComponent(ChoixStub)
            .vm.$emit('update:modelValue', 'toutes');

        expect(routerGet).toHaveBeenCalledWith(
            URL_PAGE,
            { date_debut: '2026-10-01', date_fin: '2026-11-23' },
            expect.any(Object),
        );
    });

    it('affiche le refus du serveur pour une agence hors périmètre', async () => {
        routerGet.mockImplementationOnce(
            (
                _url: string,
                _params: unknown,
                options: { onError: (e: Record<string, string>) => void },
            ) =>
                options.onError({
                    site_id: 'Agence inconnue ou hors de votre périmètre.',
                }),
        );
        const wrapper = monter();
        await wrapper
            .findComponent(ChoixStub)
            .vm.$emit('update:modelValue', 'site-matoto');

        expect(wrapper.find('[data-testid="agence-erreur"]').text()).toBe(
            'Agence inconnue ou hors de votre périmètre.',
        );
        expect(wrapper.findComponent(ChoixStub).props('modelValue')).toBe(
            'toutes',
        );
    });
});

describe('parametresPeriode', () => {
    const base = { options: [], libelle: '' };

    it('rejoue une période prédéfinie par sa clé, une période personnalisée par ses dates', () => {
        expect(
            parametresPeriode({
                ...base,
                cle: 'ce_mois',
                date_debut: '2026-11-01',
                date_fin: '2026-11-30',
            }),
        ).toEqual({ periode: 'ce_mois' });
        expect(
            parametresPeriode({
                ...base,
                cle: 'personnalisee',
                date_debut: '2026-10-01',
                date_fin: '2026-11-23',
            }),
        ).toEqual({ date_debut: '2026-10-01', date_fin: '2026-11-23' });
        expect(
            parametresPeriode({
                ...base,
                cle: 'tout',
                date_debut: null,
                date_fin: null,
            }),
        ).toEqual({ periode: 'tout' });
    });
});
