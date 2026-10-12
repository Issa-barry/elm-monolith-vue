import MouvementCreate from '@/pages/Comptabilite/MouvementsFonds/Create.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('@/layouts/AppLayout.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
    };
});
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        useForm: (donnees: Record<string, unknown>) =>
            reactive({
                ...donnees,
                errors: {},
                processing: false,
                post: vi.fn(),
            }),
    };
});

const PROPS = {
    sites: [
        { value: 'principale', label: 'Agence Principale' },
        { value: 'matoto', label: 'Matoto' },
    ],
    comptes_tresorerie: [
        {
            id: 'caisse-principale',
            site_id: 'principale',
            libelle: 'Caisse de Agence Principale',
            type: 'caisse',
        },
        {
            id: 'banque-principale',
            site_id: 'principale',
            libelle: 'UBA',
            type: 'banque',
        },
        {
            id: 'caisse-matoto',
            site_id: 'matoto',
            libelle: 'Caisse Matoto',
            type: 'caisse',
        },
    ],
    peut_approvisionner: true,
    caisses_agents: [
        {
            id: 'agent-moussa',
            site_id: 'principale',
            libelle: 'Caisse Moussa',
            agent: 'Moussa SIDIBE',
            est_ma_caisse: true,
        },
        {
            id: 'agent-bakary',
            site_id: 'principale',
            libelle: 'Caisse Bakary',
            agent: 'Bakary CAMARA',
            est_ma_caisse: false,
        },
    ],
    site_prerempli: null,
    montant_prerempli: null,
    echeance_debut_prerempli: null,
    echeance_fin_prerempli: null,
};

const monter = (surcharge: Partial<typeof PROPS> = {}) =>
    mount(MouvementCreate, { props: { ...PROPS, ...surcharge } });

type Page = ReturnType<typeof monter>;

// Ordre des listes : site d'origine, support d'origine, site de destination.
const choisir = (page: Page, position: number, valeur: string) =>
    page.findAll('select')[position].setValue(valeur);
const supportDestination = (page: Page) =>
    page.find('[data-testid="mouvement-support-destination"]');
const valider = (page: Page) => page.get('[data-testid="mouvement-valider"]');

describe('Nouveau mouvement de fonds', () => {
    it('entre agences : aucun support de destination à choisir, il le sera à la réception', async () => {
        const page = monter();
        await choisir(page, 0, 'principale');
        await choisir(page, 1, 'caisse-principale');
        await choisir(page, 2, 'matoto');

        expect(supportDestination(page).exists()).toBe(false);
        expect(
            page.find('[data-testid="mouvement-note-reception"]').exists(),
        ).toBe(true);
        expect(page.text()).toContain('Période de financement visée');
        expect(valider(page).text()).toBe('Créer le brouillon');
        expect(valider(page).attributes('disabled')).toBeUndefined();
    });

    it("même agence : propose les caisses d'agents de cette agence et envoie un approvisionnement", async () => {
        const page = monter();
        await choisir(page, 0, 'principale');
        await choisir(page, 1, 'caisse-principale');
        await choisir(page, 2, 'principale');

        const options = supportDestination(page)
            .findAll('option')
            .map((o) => o.text());
        expect(options).toEqual([
            "Sélectionner la caisse d'un agent…",
            'Moussa SIDIBE — Caisse Moussa',
            'Bakary CAMARA — Caisse Bakary',
        ]);
        expect(
            page.get('[data-testid="mouvement-note-approvisionnement"]').text(),
        ).toContain("réception par l'agent");
        expect(page.text()).not.toContain('Période de financement visée');
        expect(page.text()).not.toContain('Moyen de transfert');
        expect(valider(page).text()).toBe('Approvisionner la caisse');
        expect(valider(page).attributes('disabled')).toBeUndefined();

        await supportDestination(page).setValue('agent-moussa');
        expect(
            page.get('[data-testid="mouvement-note-approvisionnement"]').text(),
        ).toContain('réception par vous-même');
    });

    it("réinitialise la caisse choisie quand la destination n'est plus la même agence", async () => {
        const page = monter();
        await choisir(page, 0, 'principale');
        await choisir(page, 1, 'caisse-principale');
        await choisir(page, 2, 'principale');
        await supportDestination(page).setValue('agent-bakary');

        await choisir(page, 2, 'matoto');
        expect(supportDestination(page).exists()).toBe(false);

        await choisir(page, 2, 'principale');
        expect(
            (supportDestination(page).element as HTMLSelectElement).value,
        ).toBe('');
    });

    it("même agence sans caisse d'agent : message explicite et validation bloquée", async () => {
        const page = monter();
        await choisir(page, 0, 'matoto');
        await choisir(page, 1, 'caisse-matoto');
        await choisir(page, 2, 'matoto');

        expect(
            page.get('[data-testid="mouvement-aucune-caisse-agent"]').text(),
        ).toContain("Aucune caisse d'agent active");
        expect(supportDestination(page).attributes('disabled')).toBeDefined();
        expect(valider(page).attributes('disabled')).toBeDefined();
    });

    it("même agence depuis une banque : seule une caisse de l'agence approvisionne", async () => {
        const page = monter();
        await choisir(page, 0, 'principale');
        await choisir(page, 1, 'banque-principale');
        await choisir(page, 2, 'principale');

        expect(
            page.find('[data-testid="mouvement-origine-non-caisse"]').exists(),
        ).toBe(true);
        expect(valider(page).attributes('disabled')).toBeDefined();
    });

    it("sans le droit d'envoyer, l'agence d'origine n'est jamais proposée en destination", async () => {
        const page = monter({ peut_approvisionner: false, caisses_agents: [] });
        await choisir(page, 2, 'principale');
        await choisir(page, 0, 'principale');

        const destinations = page
            .findAll('select')[2]
            .findAll('option')
            .map((o) => o.text());
        expect(destinations).toEqual(['Sélectionner…', 'Matoto']);
        expect(
            (page.findAll('select')[2].element as HTMLSelectElement).value,
        ).toBe('');
        expect(supportDestination(page).exists()).toBe(false);
    });
});
