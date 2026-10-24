import CommissionMontantFixeEditor from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import PartParrainDialog from '../PartParrainDialog.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { put: vi.fn() } }));

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

const groupe = (code: string, label: string) => ({
    processus_code: code,
    processus_label: label,
    categorie_id: 'cat-1',
    categorie_nom: "Sachet d'eau",
    bareme: 1000,
    part_parrain_actuelle: 0,
    membres: [
        {
            livreur_id: 'l1',
            nom_complet: 'Saa',
            role: 'chauffeur',
            montant_actuel: 600,
        },
        {
            livreur_id: 'l2',
            nom_complet: 'Moussa',
            role: 'convoyeur',
            montant_actuel: 400,
        },
    ],
});

async function monter() {
    const wrapper = mount(PartParrainDialog, {
        props: { visible: true, vehiculeId: 'v1', parrainNom: 'Ousmane' },
        global: { plugins: [PrimeVue], stubs: { Dialog: DialogStub } },
    });
    await flushPromises();
    return wrapper;
}

const boutonEnregistrer = (w: Awaited<ReturnType<typeof monter>>) =>
    w.get('[data-testid="part-parrain-enregistrer-btn"]');

describe('PartParrainDialog — part du parrain (PARRAIN-021)', () => {
    beforeEach(() => {
        vi.mocked(router.put).mockReset();
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                parrain_id: 'p1',
                statut: 'a_definir',
                groupes: [
                    groupe('vente', 'Ventes'),
                    groupe('distribution_client', 'Distribution client'),
                ],
            }),
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('affiche un éditeur par partage du parrain', async () => {
        const w = await monter();
        expect(w.findAllComponents(CommissionMontantFixeEditor)).toHaveLength(
            2,
        );
    });

    it('exige la confirmation explicite d’une part entièrement nulle', async () => {
        const w = await monter();

        expect(
            w.find('[data-testid="part-parrain-confirmer-nulle"]').exists(),
        ).toBe(true);
        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();

        w.getComponent(Checkbox).vm.$emit('update:modelValue', true);
        await w.vm.$nextTick();
        expect(boutonEnregistrer(w).attributes('disabled')).toBeUndefined();

        await boutonEnregistrer(w).trigger('click');
        const [url, data] = vi.mocked(router.put).mock.calls[0];
        expect(url).toBe('/backoffice/vehicules/v1/parrain/part');
        expect(data).toMatchObject({
            parrain_id: 'p1',
            confirmer_part_nulle: true,
        });
    });

    it('enregistre une part positive partout sans confirmation, une fois chaque enveloppe équilibrée', async () => {
        const w = await monter();

        for (const editeur of w.findAllComponents(
            CommissionMontantFixeEditor,
        )) {
            editeur.vm.$emit('update:modelValue', [
                { id: 'l1', label: 'Chauffeur', montant_unitaire: 500 },
                { id: 'l2', label: 'Convoyeur', montant_unitaire: 300 },
            ]);
            editeur.vm.$emit('update:partParrain', 200);
        }
        await w.vm.$nextTick();

        expect(
            w.find('[data-testid="part-parrain-confirmer-nulle"]').exists(),
        ).toBe(false);
        expect(boutonEnregistrer(w).attributes('disabled')).toBeUndefined();

        await boutonEnregistrer(w).trigger('click');
        const data = vi.mocked(router.put).mock.calls[0][1] as {
            confirmer_part_nulle: boolean;
            repartitions: Array<{
                processus_code: string;
                part_parrain: number;
            }>;
        };
        expect(data.confirmer_part_nulle).toBe(false);
        expect(
            data.repartitions.map((r) => [r.processus_code, r.part_parrain]),
        ).toEqual([
            ['vente', 200],
            ['distribution_client', 200],
        ]);
    });

    it('exige la confirmation pour un partage laissé à 0 même si un autre reçoit une part (PARRAIN-022)', async () => {
        const w = await monter();
        const [vente] = w.findAllComponents(CommissionMontantFixeEditor);
        vente.vm.$emit('update:modelValue', [
            { id: 'l1', label: 'Chauffeur', montant_unitaire: 500 },
            { id: 'l2', label: 'Convoyeur', montant_unitaire: 300 },
        ]);
        vente.vm.$emit('update:partParrain', 200);
        await w.vm.$nextTick();

        const confirmation = w.get(
            '[data-testid="part-parrain-confirmer-nulle"]',
        );
        expect(confirmation.text()).toContain('Distribution client');
        expect(confirmation.text()).not.toContain('Ventes');
        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();
    });

    it('envoie la date de début des droits et prévient du recalcul pour une date passée (PARRAIN-023)', async () => {
        const w = await monter();
        await w
            .get('[data-testid="part-parrain-date-effet-input"]')
            .setValue('2020-01-01');

        expect(
            w.find('[data-testid="part-parrain-date-passee"]').text(),
        ).toContain('recalculées');

        w.getComponent(Checkbox).vm.$emit('update:modelValue', true);
        await w.vm.$nextTick();
        await boutonEnregistrer(w).trigger('click');
        expect(vi.mocked(router.put).mock.calls[0][1]).toMatchObject({
            date_effet: '2020-01-01',
        });
    });

    it('exige de confirmer le remplacement d’une répartition planifiée (PARRAIN-023)', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                parrain_id: 'p1',
                statut: 'definie',
                planification: '2099-01-16',
                date_effet_min: null,
                groupes: [groupe('vente', 'Ventes')],
            }),
        }) as unknown as typeof fetch;
        const w = await monter();
        const editeur = w.getComponent(CommissionMontantFixeEditor);
        editeur.vm.$emit('update:modelValue', [
            { id: 'l1', label: 'Chauffeur', montant_unitaire: 500 },
            { id: 'l2', label: 'Convoyeur', montant_unitaire: 300 },
        ]);
        editeur.vm.$emit('update:partParrain', 200);
        await w.vm.$nextTick();

        const remplacer = w.get(
            '[data-testid="part-parrain-remplacer-planification"]',
        );
        expect(remplacer.text()).toContain('16/01/2099');
        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();

        remplacer.getComponent(Checkbox).vm.$emit('update:modelValue', true);
        await w.vm.$nextTick();
        await boutonEnregistrer(w).trigger('click');
        expect(vi.mocked(router.put).mock.calls[0][1]).toMatchObject({
            remplacer_planification: true,
        });
    });

    it('bloque l’enregistrement tant qu’une enveloppe dépasse le barème', async () => {
        const w = await monter();
        w.findAllComponents(CommissionMontantFixeEditor)[0].vm.$emit(
            'update:partParrain',
            200,
        );
        await w.vm.$nextTick();

        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();
    });
});
