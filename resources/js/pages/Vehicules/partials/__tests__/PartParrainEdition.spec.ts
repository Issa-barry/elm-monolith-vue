import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import InputNumber from 'primevue/inputnumber';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PartParrainEdition from '../PartParrainEdition.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { put: vi.fn() } }));

const membre = (id: string, role: string, montant: number) => ({
    livreur_id: id,
    nom_complet: id,
    role,
    montant_actuel: montant,
});

/** Ventes : un seul livreur ; Distribution : deux livreurs (600 / 400). */
const groupes = [
    {
        processus_code: 'vente',
        processus_label: 'Ventes',
        categorie_id: 'cat-1',
        categorie_nom: "Bouteille d'eau",
        bareme: 400,
        part_parrain_actuelle: 0,
        membres: [membre('l1', 'chauffeur', 400)],
    },
    {
        processus_code: 'distribution_client',
        processus_label: 'Distribution client',
        categorie_id: 'cat-1',
        categorie_nom: "Bouteille d'eau",
        bareme: 1000,
        part_parrain_actuelle: 0,
        membres: [
            membre('l1', 'chauffeur', 600),
            membre('l2', 'convoyeur', 400),
        ],
    },
];

async function monter() {
    const wrapper = mount(PartParrainEdition, {
        props: { vehiculeId: 'v1' },
        global: { plugins: [PrimeVue] },
    });
    await flushPromises();
    return wrapper;
}
type Wrapper = Awaited<ReturnType<typeof monter>>;

function champ(w: Wrapper, code: string) {
    const trouve = w
        .findAllComponents(InputNumber)
        .find(
            (c) => c.attributes('data-testid') === `part-parrain-input-${code}`,
        );
    if (!trouve) throw new Error(`Champ ${code} introuvable`);
    return trouve;
}

async function saisir(w: Wrapper, code: string, valeur: number) {
    champ(w, code).vm.$emit('update:modelValue', valeur);
    await w.vm.$nextTick();
}

const texte = (w: Wrapper, testid: string) =>
    w.find(`[data-testid="${testid}"]`);
const boutonEnregistrer = (w: Wrapper) =>
    w.get('[data-testid="part-parrain-enregistrer-btn"]');

describe('PartParrainEdition — saisie en place de la part du parrain (PARRAIN-024)', () => {
    beforeEach(() => {
        vi.mocked(router.put).mockReset();
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                parrain_id: 'p1',
                statut: 'a_definir',
                groupes,
                planification: null,
                date_effet_min: null,
                aujourdhui: '2030-01-15',
            }),
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('affiche une ligne modifiable par partage, sans ligne « jamais rémunéré »', async () => {
        const w = await monter();

        expect(champ(w, 'vente').exists()).toBe(true);
        expect(champ(w, 'distribution_client').exists()).toBe(true);
        expect(w.text()).not.toContain('Jamais rémunéré');
    });

    it("prend la date du jour du serveur, jamais l'horloge du poste", async () => {
        const w = await monter();

        expect(
            (
                w.get('[data-testid="part-parrain-date-effet-input"]')
                    .element as HTMLInputElement
            ).value,
        ).toBe('2030-01-15');
    });

    it('un seul livreur : sa part est le reste du barème', async () => {
        const w = await monter();
        await saisir(w, 'vente', 100);

        expect(texte(w, 'part-parrain-livreur-vente').text()).toContain(
            '300 GNF',
        );
        expect(texte(w, 'part-parrain-a-repartir-vente').exists()).toBe(false);
    });

    it('plusieurs livreurs : la répartition est à refaire dans Équipe', async () => {
        const w = await monter();
        await saisir(w, 'distribution_client', 200);

        expect(
            texte(w, 'part-parrain-a-repartir-distribution_client').exists(),
        ).toBe(true);
        expect(texte(w, 'part-parrain-avertissement-equipe').text()).toContain(
            'Distribution client',
        );
    });

    it('ramène au barème une part qui le dépasse, avec un message', async () => {
        const w = await monter();
        await saisir(w, 'vente', 900);

        expect(texte(w, 'part-parrain-alerte-vente').text()).toContain(
            'La part ne peut pas dépasser 400 GNF. Elle a été ramenée à 400 GNF.',
        );
        expect(texte(w, 'part-parrain-livreur-vente').text()).toContain(
            '0 GNF',
        );
    });

    it('une part à 0 exige la confirmation explicite', async () => {
        const w = await monter();
        await saisir(w, 'distribution_client', 0);

        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();
        await w.getComponent(Checkbox).vm.$emit('update:modelValue', true);
        expect(boutonEnregistrer(w).attributes('disabled')).toBeUndefined();
    });

    it('une répartition à refaire dans Équipe interdit une date future', async () => {
        const w = await monter();
        await saisir(w, 'vente', 100);
        await saisir(w, 'distribution_client', 200);
        await w
            .get('[data-testid="part-parrain-date-effet-input"]')
            .setValue('2099-01-01');

        expect(texte(w, 'part-parrain-date-incompatible').exists()).toBe(true);
        expect(boutonEnregistrer(w).attributes('disabled')).toBeDefined();
    });

    it('avec une répartition à refaire, une date passée reste en attente de l’onglet Équipe', async () => {
        const w = await monter();
        await saisir(w, 'vente', 100);
        await saisir(w, 'distribution_client', 200);
        await w
            .get('[data-testid="part-parrain-date-effet-input"]')
            .setValue('2020-01-01');

        expect(texte(w, 'part-parrain-date-incompatible').exists()).toBe(false);
        expect(texte(w, 'part-parrain-date-en-attente').text()).toContain(
            'onglet Équipe',
        );
        expect(boutonEnregistrer(w).attributes('disabled')).toBeUndefined();
    });

    it('Annuler ferme sans rien enregistrer', async () => {
        const w = await monter();
        await saisir(w, 'vente', 100);
        await w
            .get('[data-testid="part-parrain-annuler-btn"]')
            .trigger('click');

        expect(w.emitted('fermer')).toHaveLength(1);
        expect(router.put).not.toHaveBeenCalled();
    });

    it('Enregistrer envoie la part de chaque partage', async () => {
        const w = await monter();
        await saisir(w, 'vente', 100);
        await saisir(w, 'distribution_client', 200);
        await boutonEnregistrer(w).trigger('click');

        expect(router.put).toHaveBeenCalledTimes(1);
        const [url, donnees] = vi.mocked(router.put).mock.calls[0];
        expect(url).toBe('/backoffice/vehicules/v1/parrain/part');
        expect(donnees).toMatchObject({
            parrain_id: 'p1',
            parts: [
                {
                    processus_code: 'vente',
                    categorie_id: 'cat-1',
                    part_parrain: 100,
                },
                {
                    processus_code: 'distribution_client',
                    categorie_id: 'cat-1',
                    part_parrain: 200,
                },
            ],
        });
    });
});
