import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ParrainDialog from '../ParrainDialog.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: vi.fn(), post: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        processing: false,
        errors: {},
        data: () => data,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    }),
}));

// Dialog PrimeVue remplacé par un conteneur simple : contenu et pied rendus si visible.
const DialogStub = defineComponent({
    props: { visible: Boolean },
    setup(props, { slots }) {
        return () =>
            props.visible
                ? h('div', [slots.default?.(), slots.footer?.()])
                : null;
    },
});

const membre = (id: string, nom: string) => ({
    livreur_id: id,
    nom_complet: nom,
    role: 'chauffeur',
    montant_actuel: 400,
});

async function monterRetrait() {
    const wrapper = mount(ParrainDialog, {
        props: {
            visible: false,
            vehiculeId: 'v1',
            mode: 'retirer',
            partPositive: true,
            parrainActuel: {
                nom_complet: 'Hassan Diallo',
                telephone: '+224666177007',
                code_pays: 'GN',
                code_phone_pays: '+224',
                pays: 'Guinée',
                ville: null,
                adresse: null,
            },
        },
        global: {
            plugins: [PrimeVue],
            stubs: { Dialog: DialogStub, Dropdown: true, InputText: true },
        },
    });
    // Ouverture comme sur la page : le contenu s'initialise au passage à visible.
    await wrapper.setProps({ visible: true });
    await flushPromises();
    return wrapper;
}

describe('ParrainDialog — retrait d’un parrain qui a une part (PARRAIN-024)', () => {
    beforeEach(() => {
        vi.mocked(router.delete).mockReset();
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                groupes: [
                    {
                        processus_code: 'vente',
                        processus_label: 'Ventes',
                        categorie_id: 'cat-1',
                        categorie_nom: "Bouteille d'eau",
                        bareme: 400,
                        part_parrain_actuelle: 100,
                        au_retrait: true,
                        membres: [membre('l1', 'Saa Fodé')],
                    },
                    {
                        processus_code: 'transfert_grossiste',
                        processus_label: 'Transferts grossistes',
                        categorie_id: 'cat-1',
                        categorie_nom: "Bouteille d'eau",
                        bareme: 400,
                        part_parrain_actuelle: 50,
                        au_retrait: true,
                        membres: [
                            membre('l1', 'Saa Fodé'),
                            membre('l2', 'Moussa'),
                        ],
                    },
                ],
            }),
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('annonce les conséquences par partage, sans aucune saisie de répartition', async () => {
        const w = await monterRetrait();

        expect(w.find('[data-testid="parrain-consequences"]').text()).toContain(
            'conserve toutes ses commissions acquises',
        );
        expect(
            w.get('[data-testid="parrain-consequence-vente"]').text(),
        ).toContain('rendus à Saa Fodé');
        expect(
            w
                .get('[data-testid="parrain-consequence-transfert_grossiste"]')
                .text(),
        ).toContain("à répartir entre les 2 livreurs dans l'onglet Équipe");
        expect(w.find('input').exists()).toBe(false);
    });

    it('confirme le retrait sans joindre de répartition', async () => {
        const w = await monterRetrait();
        await w
            .get('[data-testid="parrain-changement-confirmer-btn"]')
            .trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        const [url, options] = vi.mocked(router.delete).mock.calls[0];
        expect(url).toBe('/backoffice/vehicules/v1/parrain');
        expect(options).not.toHaveProperty('data');
    });
});
