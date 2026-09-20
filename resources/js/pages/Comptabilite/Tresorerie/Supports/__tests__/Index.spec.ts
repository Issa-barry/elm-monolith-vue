import KpiCardsResponsive from '@/components/dashboard/shared/KpiCardsResponsive.vue';
import StatusDot from '@/components/StatusDot.vue';
import SupportsIndex from '@/pages/Comptabilite/Tresorerie/Supports/Index.vue';
import type { CompteTresorerie } from '@/pages/Comptabilite/Tresorerie/Supports/partials/presentation';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const etat = vi.hoisted(() => ({ permissions: [] as string[] }));

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({
        can: (permission: string) => etat.permissions.includes(permission),
    }),
}));
vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
vi.mock('primevue/useconfirm', () => ({
    useConfirm: () => ({ require: vi.fn() }),
}));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));
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
        router: { put: vi.fn(), post: vi.fn(), get: vi.fn() },
        useForm: (donnees: Record<string, unknown>) => {
            const formulaire = reactive({
                ...donnees,
                errors: {},
                processing: false,
                reset: vi.fn(),
                clearErrors: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
            });

            return Object.assign(formulaire, { transform: () => formulaire });
        },
    };
});

const compte = (surcharge: Partial<CompteTresorerie>): CompteTresorerie => ({
    id: 'c1',
    site: 'Matoto',
    site_id: 's1',
    type: 'caisse',
    type_label: 'Caisse',
    libelle: 'Caisse-espèce',
    nature: 'agence',
    agent: null,
    compte_comptable_id: 'cc1',
    compte_numero: '571000',
    moyen_paiement_defaut: null,
    actif: true,
    statut: 'actif',
    statut_label: 'Actif',
    valide_le: '2026-09-01T10:00:00+00:00',
    valide_par: null,
    peut_valider: false,
    solde: 266_824_000,
    en_cours_versement: 0,
    versements_en_cours: 0,
    peut_verser: false,
    solde_ouverture: null,
    ...surcharge,
});

const COMPTES: CompteTresorerie[] = [
    compte({
        id: 'c-agent',
        libelle: 'Caisse Moussa sidibé',
        nature: 'dediee',
        agent: { id: 'u1', nom: 'Moussa sidibé' },
        compte_numero: '571001',
        solde: 1_000_000,
        peut_verser: true,
    }),
    compte({ id: 'c-agence' }),
    compte({
        id: 'c-uba',
        libelle: 'UBA',
        type: 'banque',
        type_label: 'Banque',
        compte_numero: '521000',
        solde: 0,
        actif: false,
        statut: 'inactif',
        statut_label: 'Inactif',
        solde_ouverture: { id: 'so1', montant: 500, statut: 'valide' },
    }),
];

const BROUILLON = compte({
    id: 'c-brouillon',
    libelle: 'Caisse en préparation',
    actif: false,
    statut: 'brouillon',
    statut_label: 'Brouillon',
    valide_le: null,
    peut_valider: true,
    solde: 0,
});

const monter = (comptes: CompteTresorerie[] = COMPTES, filtre = false) =>
    shallowMount(SupportsIndex, {
        props: {
            comptes,
            filters: {
                site_ids: [],
                statut: filtre ? 'actif' : '',
                type: '',
                nature: '',
                agent_id: '',
            },
            sites: [],
            type_options: [],
            destinations_versement: [],
            agents: [],
            caisses_dediees_actives: [],
            agents_filtre: [],
            comptes_comptables: [],
        },
        global: { renderStubDefaultSlot: true },
    });

const lignes = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findAll('[data-testid="support-row"]');

describe('Supports de trésorerie — tableau', () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    it('présente Agence, Caisse, Nature, Responsable, Solde, Statut et Actions', () => {
        const colonnes = monter()
            .findAll('thead th')
            .map((entete) => entete.text());

        expect(colonnes).toEqual([
            'Agence',
            'Caisse',
            'Nature',
            'Responsable',
            'Solde',
            'Statut',
            'Actions',
        ]);
    });

    it('affiche une caisse dédiée avec son agent, sa nature et le compte comptable', () => {
        const ligne = lignes(monter())[0];

        expect(ligne.text()).toContain('Caisse Moussa sidibé');
        expect(ligne.text()).toContain('Compte 571001');
        expect(ligne.find('[data-testid="support-nature"]').text()).toBe(
            'Caisse dédiée',
        );
        // Responsable : le nom de l'agent, jamais « Agence ».
        expect(ligne.findAll('td')[3].text()).toBe('Moussa sidibé');
        expect(ligne.find('[data-testid="support-solde"]').text()).toBe(
            '1 000 000 GNF',
        );
    });

    it("affiche « Agence » en badge pour un support de l'agence, avec sa nature", () => {
        const [, caisse, banque] = lignes(monter());

        expect(caisse.find('[data-testid="support-nature"]').text()).toBe(
            'Caisse agence',
        );
        expect(caisse.findAll('td')[3].text()).toBe('Agence');
        expect(caisse.find('[data-testid="support-solde"]').text()).toBe(
            '266 824 000 GNF',
        );
        expect(banque.find('[data-testid="support-nature"]').text()).toBe(
            'Banque',
        );
        // Un seul statut par ligne (celui du support) : le solde d'ouverture validé n'est pas
        // rappelé à côté, il ne peut plus passer pour un second statut.
        const points = banque.findAllComponents(StatusDot);
        expect(points).toHaveLength(1);
        expect(points[0].props('label')).toBe('Inactif');
        expect(points[0].props('status')).toBe('inactif');
        expect(banque.text()).not.toContain("Solde d'ouverture");
        expect(banque.text()).not.toContain('Validé');
    });

    it('résume les supports, les caisses dédiées et le solde total sans le confondre avec le Disponible', () => {
        const items = monter().findComponent(KpiCardsResponsive).props('items');

        expect(items.map((i: { value: string }) => i.value)).toEqual([
            '3',
            '1',
            '267 824 000 GNF',
            '0 GNF',
        ]);
        // Le montant suit les filtres : il ne doit pas passer pour toute la trésorerie.
        expect(items[2].title).toBe('Solde total des supports affichés');
        expect(items[2].subtitle).toContain('Disponible de Financement');
    });

    it('signale que le résumé suit les filtres actifs', () => {
        const items = monter(COMPTES, true)
            .findComponent(KpiCardsResponsive)
            .props('items');

        expect(items[0].subtitle).toBe('Selon les filtres actifs');
    });
});

describe('Supports de trésorerie — actions selon les permissions', () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    it("n'affiche aucun menu d'actions sans la permission de gérer les supports", () => {
        const wrapper = monter();

        expect(wrapper.find('[data-testid="support-actions"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).not.toContain('Modifier');
        expect(wrapper.text()).not.toContain('Désactiver');
    });

    it("garde « Verser à l'agence » lié à l'indicateur serveur peut_verser, sans permission de gestion", () => {
        const [agent, agence] = lignes(monter());

        expect(agent.find('[data-testid="support-verser"]').exists()).toBe(
            true,
        );
        expect(agence.find('[data-testid="support-verser"]').exists()).toBe(
            false,
        );
    });

    it('propose les actions de gestion à ceux qui gèrent les supports, selon la ligne', () => {
        etat.permissions = ['tresorerie.gerer_soldes_ouverture'];
        const [agent, agence, banque] = lignes(monter());

        expect(agent.find('[data-testid="support-actions"]').exists()).toBe(
            true,
        );
        expect(agent.text()).toContain('Modifier');
        expect(agent.text()).toContain('Désactiver');
        expect(agent.text()).not.toContain("Saisir le solde d'ouverture");

        expect(agence.text()).toContain("Saisir le solde d'ouverture");
        expect(agence.text()).toContain('Désactiver');

        expect(banque.text()).not.toContain("Saisir le solde d'ouverture");
        expect(banque.text()).toContain('Réactiver');
        expect(banque.text()).not.toContain('Désactiver');
    });

    it('affiche un message adapté quand aucun support ne correspond aux filtres', () => {
        const wrapper = monter([], true);

        expect(wrapper.text()).toContain(
            'Aucun support ne correspond à ces filtres.',
        );
    });
});

describe("Supports de trésorerie — solde d'ouverture : alerte seulement si une action est requise", () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    it("signale en ambre un solde d'ouverture à saisir sur un support actif d'agence", () => {
        const [ligne] = lignes(monter([compte({ solde_ouverture: null })]));

        const alerte = ligne.find('[data-testid="support-alerte-solde"]');
        expect(alerte.text()).toBe("Solde d'ouverture à saisir");
        expect(alerte.classes()).toContain('text-amber-600');
    });

    it("signale un solde d'ouverture à valider avec son montant, sans le compter dans le solde", () => {
        const [ligne] = lignes(
            monter([
                compte({
                    solde_ouverture: {
                        id: 'so',
                        montant: 250_000,
                        statut: 'brouillon',
                    },
                }),
            ]),
        );

        const texte = ligne.find('[data-testid="support-alerte-solde"]').text();
        expect(texte).toContain("Solde d'ouverture à valider");
        expect(texte).toContain('250 000');
        expect(texte).toContain('non compté dans le solde');
    });

    it("n'affiche rien d'un solde d'ouverture validé, d'une caisse dédiée ni d'un brouillon", () => {
        const [valide, dediee, brouillon] = lignes(
            monter([
                compte({
                    id: 'v',
                    solde_ouverture: { id: 'so', montant: 1, statut: 'valide' },
                }),
                compte({
                    id: 'd',
                    nature: 'dediee',
                    agent: { id: 'u', nom: 'Moussa' },
                }),
                { ...BROUILLON, id: 'b' },
            ]),
        );

        for (const ligne of [valide, dediee, brouillon]) {
            expect(
                ligne.find('[data-testid="support-alerte-solde"]').exists(),
            ).toBe(false);
        }
    });
});

describe('Supports de trésorerie — versements en cours', () => {
    // Caisse de l'agent : 850 000 avant, 800 000 envoyés → 50 000 de solde, 800 000 en cours.
    const CAISSE_QUI_VERSE = compte({
        id: 'c-verse',
        libelle: 'Caisse Saa Fodé',
        nature: 'dediee',
        agent: { id: 'u1', nom: 'Saa Fodé' },
        solde: 50_000,
        en_cours_versement: 800_000,
        versements_en_cours: 1,
    });

    beforeEach(() => {
        etat.permissions = [];
    });

    const cartes = (comptes: CompteTresorerie[]) =>
        monter(comptes).findComponent(KpiCardsResponsive).props('items') as {
            id: string;
            title: string;
            value: string;
            subtitle: string;
            note?: string;
        }[];

    it('affiche « En cours de versement » à part du solde, avec le nombre de versements à confirmer', () => {
        const items = cartes([
            CAISSE_QUI_VERSE,
            compte({ id: 'c-agence', solde: 0 }),
        ]);
        const solde = items.find((i) => i.id === 'solde-total');
        const enCours = items.find((i) => i.id === 'en-cours-versement');

        // Solde ≠ en cours de versement : les 800 000 ne sont dans aucun solde.
        expect(solde?.value).toBe('50 000 GNF');
        expect(enCours?.title).toBe('En cours de versement');
        expect(enCours?.value).toBe('800 000 GNF');
        expect(enCours?.subtitle).toBe('1 versement à confirmer');
        expect(enCours?.note).toContain('pas encore reçu');
    });

    it('affiche 0 GNF et « Aucun versement à confirmer » sans versement en cours', () => {
        const enCours = cartes([compte({})]).find(
            (i) => i.id === 'en-cours-versement',
        );

        expect(enCours?.value).toBe('0 GNF');
        expect(enCours?.subtitle).toBe('Aucun versement à confirmer');
        expect(enCours?.note).toBeUndefined();
    });

    it('détaille le versement sous le solde de la caisse qui verse, sans changer ce solde', () => {
        const [ligne] = lignes(monter([CAISSE_QUI_VERSE]));

        expect(ligne.find('[data-testid="support-solde"]').text()).toBe(
            '50 000 GNF',
        );
        expect(
            ligne.find('[data-testid="support-en-cours-versement"]').text(),
        ).toBe('En cours de versement : 800 000 GNF');
    });

    it("n'affiche rien de plus sur une caisse sans versement en cours", () => {
        const [ligne] = lignes(monter([compte({})]));

        expect(
            ligne.find('[data-testid="support-en-cours-versement"]').exists(),
        ).toBe(false);
    });
});

describe("Supports de trésorerie — cycle de vie d'un support", () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    it('affiche un statut unique par ligne : Brouillon, Actif ou Inactif', () => {
        const rows = lignes(monter([BROUILLON, ...COMPTES]));

        const statuts = rows.map((ligne) => {
            const points = ligne.findAllComponents(StatusDot);
            expect(points).toHaveLength(1);

            return [points[0].props('status'), points[0].props('label')];
        });

        expect(statuts).toEqual([
            ['brouillon', 'Brouillon'],
            ['actif', 'Actif'],
            ['actif', 'Actif'],
            ['inactif', 'Inactif'],
        ]);
    });

    it("propose « Valider » seulement avec la permission ET l'indicateur serveur peut_valider", () => {
        const sansPermission = lignes(monter([BROUILLON]))[0];
        expect(
            sansPermission.find('[data-testid="support-valider"]').exists(),
        ).toBe(false);

        etat.permissions = ['tresorerie.valider_supports'];
        const [brouillon, actif] = lignes(
            monter([BROUILLON, compte({ peut_valider: false })]),
        );
        expect(brouillon.find('[data-testid="support-valider"]').exists()).toBe(
            true,
        );
        expect(actif.find('[data-testid="support-valider"]').exists()).toBe(
            false,
        );

        const horsPerimetre = lignes(
            monter([{ ...BROUILLON, peut_valider: false }]),
        )[0];
        expect(
            horsPerimetre.find('[data-testid="support-valider"]').exists(),
        ).toBe(false);
    });

    it("permet de valider sans gérer les supports, mais sans menu d'actions", () => {
        etat.permissions = ['tresorerie.valider_supports'];
        const [ligne] = lignes(monter([BROUILLON]));

        expect(ligne.find('[data-testid="support-valider"]').exists()).toBe(
            true,
        );
        expect(ligne.find('[data-testid="support-actions"]').exists()).toBe(
            false,
        );
    });

    it("ne propose pour un brouillon ni de réactiver, ni de désactiver, ni de solde d'ouverture", () => {
        etat.permissions = ['tresorerie.gerer_soldes_ouverture'];
        const [ligne] = lignes(monter([BROUILLON]));

        expect(ligne.text()).toContain('Modifier');
        expect(ligne.text()).not.toContain('Réactiver');
        expect(ligne.text()).not.toContain('Désactiver');
        expect(ligne.text()).not.toContain("Saisir le solde d'ouverture");
    });
});

describe('Supports de trésorerie — dialogue « Modifier le support »', () => {
    type Ouvrable = { ouvrirEdition: (c: CompteTresorerie) => void };

    const ouvrir = async (c: CompteTresorerie) => {
        const wrapper = monter([c]);
        (wrapper.vm as unknown as Ouvrable).ouvrirEdition(c);
        await nextTick();

        return wrapper;
    };

    beforeEach(() => {
        etat.permissions = ['tresorerie.gerer_soldes_ouverture'];
    });

    it("montre le solde d'ouverture en lecture seule avec son état, et la trace de validation", async () => {
        const wrapper = await ouvrir(
            compte({
                valide_par: 'Aïssata Barry',
                solde_ouverture: {
                    id: 'so',
                    montant: 2_500_000,
                    statut: 'valide',
                },
            }),
        );

        const detail = wrapper.find('[data-testid="edit-solde-ouverture"]');
        expect(detail.text()).toContain("Solde d'ouverture");
        expect(detail.text()).toContain('2 500 000');
        expect(detail.findComponent(StatusDot).props('label')).toBe('Validé');
        expect(
            wrapper.find('[data-testid="edit-validation"]').text(),
        ).toContain('par Aïssata Barry');
    });

    it("indique « non saisi » et n'a pas de solde d'ouverture pour une caisse dédiée", async () => {
        const agence = await ouvrir(compte({ solde_ouverture: null }));
        expect(
            agence.find('[data-testid="edit-solde-ouverture"]').text(),
        ).toContain('non saisi');

        const dediee = await ouvrir(
            compte({ nature: 'dediee', agent: { id: 'u', nom: 'Moussa' } }),
        );
        expect(
            dediee.find('[data-testid="edit-solde-ouverture"]').exists(),
        ).toBe(false);
    });

    it("explique qu'un brouillon doit être validé et n'offre pas de case « Actif »", async () => {
        const wrapper = await ouvrir(BROUILLON);

        expect(wrapper.find('[data-testid="edit-brouillon"]').text()).toContain(
            'devra être validé',
        );
        // Pas de « non saisi » trompeur : le solde d'ouverture n'est possible qu'après validation.
        expect(
            wrapper.find('[data-testid="edit-solde-ouverture"]').text(),
        ).toContain('après la validation du support');
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="edit-validation"]').exists()).toBe(
            false,
        );
    });

    it('garde la case « Actif » pour un support validé', async () => {
        const wrapper = await ouvrir(compte({}));

        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true);
    });
});
