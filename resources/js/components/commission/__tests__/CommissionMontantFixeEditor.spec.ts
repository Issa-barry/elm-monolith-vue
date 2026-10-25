import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import InputNumber from 'primevue/inputnumber';
import { describe, expect, it } from 'vitest';

const membres: CommissionMontantFixeMembre[] = [
    { id: 'chauffeur', label: 'Chauffeur — Mamadou SY', montant_unitaire: 500 },
    {
        id: 'convoyeur',
        label: 'Convoyeur — Ibrahima BAH',
        montant_unitaire: 300,
    },
];

function monter(props: Record<string, unknown>) {
    return mount(CommissionMontantFixeEditor, {
        props: { modelValue: membres, enveloppeUnitaire: 1000, ...props },
        global: { plugins: [PrimeVue] },
    });
}

/** Champ InputNumber d'un data-testid (wrapper typé, avec vm). */
function champ(wrapper: ReturnType<typeof monter>, testid: string) {
    const trouve = wrapper
        .findAllComponents(InputNumber)
        .find((c) => c.attributes('data-testid') === testid);
    if (!trouve) throw new Error(`Champ ${testid} introuvable`);
    return trouve;
}

function etat(wrapper: ReturnType<typeof monter>): string {
    return wrapper.get('[data-testid="partage-livreur-etat"]').text();
}

describe('CommissionMontantFixeEditor — part du parrain (ADR 0035)', () => {
    it("sans parrain, n'affiche aucune ligne parrain", () => {
        const wrapper = monter({ parrain: null });

        expect(
            wrapper.find('[data-testid="partage-parrain-montant"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).not.toContain('Parrain du véhicule');
        expect(etat(wrapper)).toBe('Reste à répartir');
        expect(wrapper.text()).toContain('200 GNF');
    });

    it('compte la part du parrain dans le total réparti', () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 200,
        });

        expect(wrapper.text()).toContain('Moussa SIDIBÉ');
        expect(etat(wrapper)).toBe('Répartition équilibrée');
        expect(wrapper.vm.valide).toBe(true);
    });

    it('signale un dépassement du barème avec son montant', () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 350,
        });

        expect(etat(wrapper)).toContain('Dépassement du barème');
        expect(wrapper.text()).toContain('150 GNF');
        expect(wrapper.vm.valide).toBe(false);
    });

    it('ajuste l’équipe au prorata de ses parts, la part du parrain déduite', async () => {
        const wrapper = monter({
            modelValue: [
                { id: 'chauffeur', label: 'Chauffeur', montant_unitaire: 600 },
                { id: 'convoyeur', label: 'Convoyeur', montant_unitaire: 400 },
            ],
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 200,
        });

        await wrapper
            .get('[data-testid="partage-ajuster-prorata"]')
            .trigger('click');

        const emis = wrapper
            .emitted('update:modelValue')
            ?.at(-1)?.[0] as CommissionMontantFixeMembre[];
        expect(emis.map((m) => m.montant_unitaire)).toEqual([480, 320]);
    });

    it('met à jour la part du parrain pendant la frappe', async () => {
        const wrapper = monter({
            parrain: { nom: 'Moussa SIDIBÉ' },
            partParrain: 0,
        });

        const input = wrapper
            .findAllComponents({ name: 'InputNumber' })
            .at(-1)!;
        input.vm.$emit('input', { value: 200 });

        expect(wrapper.emitted('update:partParrain')?.at(-1)).toEqual([200]);
    });

    describe('un seul livreur et un parrain : calcul automatique', () => {
        const seul = [
            { id: 'chauffeur', label: 'Chauffeur', montant_unitaire: 400 },
        ];
        const monterSeul = (props: Record<string, unknown> = {}) =>
            monter({
                modelValue: seul,
                enveloppeUnitaire: 400,
                parrain: { nom: 'Ousmane SIDIBÉ' },
                partParrain: 0,
                ...props,
            });
        const dernier = <T>(
            w: ReturnType<typeof monter>,
            evenement: string,
        ): T => w.emitted(evenement)?.at(-1)?.[0] as T;

        it('saisir la part du parrain calcule celle du livreur', async () => {
            const w = monterSeul();
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                50,
            );
            await w.vm.$nextTick();

            expect(dernier<number>(w, 'update:partParrain')).toBe(50);
            expect(
                dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                )[0].montant_unitaire,
            ).toBe(350);
            expect(
                w.find('[data-testid="partage-livreur-calcule"]').exists(),
            ).toBe(true);
        });

        it('saisir la part du livreur calcule celle du parrain', async () => {
            const w = monterSeul();
            champ(w, 'partage-livreur-montant-chauffeur').vm.$emit(
                'update:modelValue',
                300,
            );
            await w.vm.$nextTick();

            expect(
                dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                )[0].montant_unitaire,
            ).toBe(300);
            expect(dernier<number>(w, 'update:partParrain')).toBe(100);
            expect(
                w.find('[data-testid="partage-parrain-calcule"]').exists(),
            ).toBe(true);
        });

        it('une part du parrain à 0 donne tout le barème au livreur', async () => {
            const w = monterSeul({ partParrain: 100 });
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                0,
            );
            await w.vm.$nextTick();

            expect(dernier<number>(w, 'update:partParrain')).toBe(0);
            expect(
                dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                )[0].montant_unitaire,
            ).toBe(400);
        });

        it('ramène au barème une part qui le dépasse, avec un message', async () => {
            const w = monterSeul();
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                900,
            );
            await w.vm.$nextTick();

            expect(dernier<number>(w, 'update:partParrain')).toBe(400);
            expect(
                dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                )[0].montant_unitaire,
            ).toBe(0);
            expect(
                w.get('[data-testid="partage-alerte-limite"]').text(),
            ).toContain(
                'La part ne peut pas dépasser 400 GNF. Elle a été ramenée à 400 GNF.',
            );
        });

        it('garde le message quand la valeur plafonnée revient au blur, l’efface sous le barème', async () => {
            const w = monterSeul();
            const champParrain = champ(w, 'partage-parrain-montant');
            champParrain.vm.$emit('update:modelValue', 900);
            champParrain.vm.$emit('update:modelValue', 400);
            await w.vm.$nextTick();
            expect(
                w.find('[data-testid="partage-alerte-limite"]').exists(),
            ).toBe(true);

            champParrain.vm.$emit('update:modelValue', 50);
            await w.vm.$nextTick();
            expect(
                w.find('[data-testid="partage-alerte-limite"]').exists(),
            ).toBe(false);
        });

        /** Total réparti tel qu'émis : dernière part du livreur + dernière part du parrain. */
        const totalEmis = (w: ReturnType<typeof monter>) =>
            dernier<CommissionMontantFixeMembre[]>(w, 'update:modelValue')[0]
                .montant_unitaire + dernier<number>(w, 'update:partParrain');

        it('barème 400 : parrain 100 → livreur 300, puis livreur 250 → parrain 150', async () => {
            const w = monterSeul({ modelValue: [{ ...seul[0] }] });
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                100,
            );
            expect(
                dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                )[0].montant_unitaire,
            ).toBe(300);
            expect(totalEmis(w)).toBe(400);

            champ(w, 'partage-livreur-montant-chauffeur').vm.$emit(
                'update:modelValue',
                250,
            );
            expect(dernier<number>(w, 'update:partParrain')).toBe(150);
            expect(totalEmis(w)).toBe(400);
        });

        it('pendant la frappe (avant la sortie du champ), le total reste au barème à chaque touche', () => {
            const w = monterSeul();
            const parrain = champ(w, 'partage-parrain-montant');
            for (const frappe of [1, 10, 100]) {
                parrain.vm.$emit('input', { value: frappe });
                expect(dernier<number>(w, 'update:partParrain')).toBe(frappe);
                expect(totalEmis(w)).toBe(400);
            }
            const livreur = champ(w, 'partage-livreur-montant-chauffeur');
            for (const frappe of [2, 25, 250]) {
                livreur.vm.$emit('input', { value: frappe });
                expect(dernier<number>(w, 'update:partParrain')).toBe(
                    400 - frappe,
                );
                expect(totalEmis(w)).toBe(400);
            }
        });

        it.each([
            ['partage-parrain-montant', 0, 0, 400],
            ['partage-parrain-montant', 400, 400, 0],
            ['partage-livreur-montant-chauffeur', 0, 400, 0],
            ['partage-livreur-montant-chauffeur', 400, 0, 400],
            ['partage-livreur-montant-chauffeur', 650, 0, 400],
        ])(
            'limites : %s = %i → parrain %i, livreur %i',
            (testid, saisie, parrainAttendu, livreurAttendu) => {
                const w = monterSeul({ partParrain: 100 });
                champ(w, testid).vm.$emit('update:modelValue', saisie);

                expect(dernier<number>(w, 'update:partParrain')).toBe(
                    parrainAttendu,
                );
                expect(
                    dernier<CommissionMontantFixeMembre[]>(
                        w,
                        'update:modelValue',
                    )[0].montant_unitaire,
                ).toBe(livreurAttendu);
                expect(totalEmis(w)).toBe(400);
            },
        );

        it('ne laisse jamais un total à 500 sur un barème de 400 (cas de la capture)', async () => {
            const w = monterSeul();
            await w.setProps({ partParrain: 0 });
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                100,
            );
            await w.setProps({
                partParrain: dernier<number>(w, 'update:partParrain'),
                modelValue: dernier<CommissionMontantFixeMembre[]>(
                    w,
                    'update:modelValue',
                ),
            });

            expect(etat(w)).toBe('Répartition équilibrée');
            expect(w.text()).not.toContain('500 GNF');
            expect(w.vm.valide).toBe(true);
        });

        it('avec plusieurs livreurs, ne recalcule aucune part', async () => {
            const w = monter({
                modelValue: membres,
                parrain: { nom: 'Ousmane SIDIBÉ' },
                partParrain: 0,
            });
            champ(w, 'partage-parrain-montant').vm.$emit(
                'update:modelValue',
                200,
            );
            await w.vm.$nextTick();

            expect(dernier<number>(w, 'update:partParrain')).toBe(200);
            expect(w.emitted('update:modelValue')).toBeUndefined();
            expect(w.find('[data-testid="partage-calcul-auto"]').exists()).toBe(
                false,
            );
        });

        it('sans parrain, aucun calcul automatique', () => {
            const w = monter({ modelValue: seul, enveloppeUnitaire: 400 });

            expect(w.find('[data-testid="partage-calcul-auto"]').exists()).toBe(
                false,
            );
        });
    });
});
