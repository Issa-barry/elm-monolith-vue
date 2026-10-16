import StatusDot from '@/components/StatusDot.vue';
import SupportsIndex from '@/pages/Comptabilite/Tresorerie/Supports/Index.vue';
import type { CompteTresorerie } from '@/pages/Comptabilite/Tresorerie/Supports/partials/presentation';
import { router } from '@inertiajs/vue3';
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
        router: { put: vi.fn(), post: vi.fn(), get: vi.fn(), delete: vi.fn() },
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
    operateur_mobile_money: null,
    operateur_label: null,
    libelle: 'Caisse-espèce',
    numero: null,
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
    en_cours_approvisionnement: 0,
    approvisionnements_en_cours: 0,
    peut_approvisionner: false,
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

const monter = (
    comptes: CompteTresorerie[] = COMPTES,
    filtre = false,
    supplement: Record<string, unknown> = {},
) =>
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
            operateur_options: [],
            destinations_versement: [],
            destinations_approvisionnement: [],
            agents: [],
            caisses_dediees_actives: [],
            comptes_mobile_money_agents_actifs: [],
            agents_filtre: [],
            comptes_comptables: [],
            ...supplement,
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: {
                Dialog: {
                    template: '<div><slot /><slot name="footer" /></div>',
                },
                Button: false,
                Primitive: false,
                KpiCard: false,
            },
        },
    });

const lignes = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findAll('[data-testid="support-row"]');

describe('Supports de trésorerie — formulaire de versement compact', () => {
    const ouvrir = async () => {
        const wrapper = monter();
        await wrapper.setProps({
            destinations_versement: [
                { id: 'c-agence', site_id: 's1', libelle: 'Caisse-espèce' },
                { id: 'c-autre', site_id: 's2', libelle: 'Autre agence' },
            ],
        });
        await wrapper.get('[data-testid="support-verser"]').trigger('click');
        return wrapper;
    };

    it('garde la caisse source et la destination avec les deux repères de solde', async () => {
        const wrapper = await ouvrir();
        const form = wrapper.get('[data-testid="versement-form"]');
        expect(form.text()).toContain('Caisse Moussa sidibé');
        expect(form.get('[for="vers-dest"]').text()).toContain('Matoto');
        expect(form.get('#vers-dest').element).toHaveProperty(
            'value',
            'c-agence',
        );
        expect(
            form.findAll('#vers-dest option').map((o) => o.text()),
        ).not.toContain('Autre agence');

        await form.get('#vers-montant').setValue('250000');
        const recap = form.get('[data-testid="versement-recapitulatif"]');
        expect(recap.findAll('dt').map((dt) => dt.text())).toEqual([
            'Disponible',
            'Reste après envoi',
        ]);
        expect(recap.findAll('dd').map((dd) => dd.text())).toEqual([
            '1 000 000 GNF',
            '750 000 GNF',
        ]);
    });

    it('conserve le blocage des montants nuls et supérieurs au disponible', async () => {
        const wrapper = await ouvrir();
        const envoyer = wrapper.get('[data-testid="versement-envoyer"]');
        expect(envoyer.attributes('disabled')).toBeDefined();
        await wrapper.get('#vers-montant').setValue('1000001');
        expect(envoyer.attributes('disabled')).toBeDefined();
        expect(
            wrapper.get('[data-testid="versement-depassement"]').text(),
        ).toContain('dépasse le solde disponible');
        await wrapper.get('#vers-montant').setValue('1000000');
        expect(envoyer.attributes('disabled')).toBeUndefined();
        expect(
            wrapper.find('[data-testid="versement-depassement"]').exists(),
        ).toBe(false);
    });

    it('relie le bouton du pied de fenêtre au formulaire et conserve les données envoyées', async () => {
        const wrapper = await ouvrir();
        await wrapper.get('#vers-montant').setValue('250000');
        const form = wrapper.get('[data-testid="versement-form"]');
        const envoyer = wrapper.get('[data-testid="versement-envoyer"]');
        expect(envoyer.attributes('form')).toBe(form.attributes('id'));
        expect(form.find('[data-testid="versement-envoyer"]').exists()).toBe(
            false,
        );
        await form.trigger('submit');

        const vm = wrapper.vm as unknown as {
            versementForm: {
                montant: string;
                compte_tresorerie_destination_id: string;
                motif: string;
                post: ReturnType<typeof vi.fn>;
            };
        };
        expect(vm.versementForm.montant).toBe('250000');
        expect(vm.versementForm.compte_tresorerie_destination_id).toBe(
            'c-agence',
        );
        expect(vm.versementForm.motif).toBe('Versement caisse agent');
        expect(vm.versementForm.post).toHaveBeenCalledWith(
            '/backoffice/comptabilite/tresorerie/supports/c-agent/verser',
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });
});

describe('Supports de trésorerie — tableau', () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    it('présente Agence, Caisse, Compte, Nature, Responsable, Solde, Statut et Actions', () => {
        const colonnes = monter()
            .findAll('thead th')
            .map((entete) => entete.text());

        expect(colonnes).toEqual([
            'Agence',
            'Caisse',
            'Compte',
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
        // Le numéro de compte a sa propre colonne, il n'est plus glissé sous le libellé.
        expect(ligne.find('[data-testid="support-compte"]').text()).toBe(
            '571001',
        );
        expect(ligne.findAll('td')[1].text()).not.toContain('571001');
        expect(ligne.find('[data-testid="support-nature"]').text()).toBe(
            'Caisse dédiée',
        );
        // Responsable : le nom de l'agent, jamais « Agence ».
        expect(ligne.findAll('td')[4].text()).toBe('Moussa sidibé');
        expect(ligne.find('[data-testid="support-solde"]').text()).toBe(
            '1 000 000 GNF',
        );
    });

    it("affiche « Agence » en badge pour un support de l'agence, avec sa nature", () => {
        const [, caisse, banque] = lignes(monter());

        expect(caisse.find('[data-testid="support-nature"]').text()).toBe(
            'Caisse agence',
        );
        expect(caisse.findAll('td')[4].text()).toBe('Agence');
        expect(caisse.find('[data-testid="support-compte"]').text()).toBe(
            '571000',
        );
        expect(banque.find('[data-testid="support-compte"]').text()).toBe(
            '521000',
        );
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

    it("affiche un tiret quand le support n'a pas de compte comptable", () => {
        const [ligne] = lignes(monter([compte({ compte_numero: null })]));

        expect(ligne.find('[data-testid="support-compte"]').text()).toBe('—');
    });
});

// Cartes KPI : pattern Apollo (.card, grille 12 colonnes), titre court + valeur + au plus une
// information secondaire. Aucun paragraphe explicatif : le détail métier est en infobulle.
describe('Supports de trésorerie — cartes KPI', () => {
    beforeEach(() => {
        etat.permissions = [];
    });

    const carte = (wrapper: ReturnType<typeof monter>, id: string) =>
        wrapper.find(`[data-testid="support-kpi-${id}"]`);

    const valeur = (wrapper: ReturnType<typeof monter>, id: string) =>
        carte(wrapper, id).find('[data-slot="kpi-value"]').text();

    it('présente quatre cartes courtes dans la grille Apollo (1 / 2 / 4 colonnes selon la largeur de la zone)', () => {
        const cartes = monter().findAll('[data-testid="support-kpis"] > div');

        expect(cartes).toHaveLength(4);
        expect(
            cartes.map((c) =>
                c.attributes('data-testid')?.replace('support-kpi-', ''),
            ),
        ).toEqual([
            'supports',
            'caisses-dediees',
            'solde-total',
            'en-cours-versement',
        ]);
        // Le responsive agit sur la DISPOSITION, mesurée sur la zone de contenu (container query) :
        // 4 colonnes dès 70 rem (1120 px) de zone : un montant à 10 chiffres à 31,5 px (~203 px) tient
        // alors sur une ligne dans la carte (zone / 4 − 77), « GNF » passant dessous si besoin.
        for (const c of cartes) {
            expect(c.classes()).toEqual(
                expect.arrayContaining([
                    'col-span-12',
                    '@[40rem]:col-span-6',
                    '@[70rem]:col-span-3',
                ]),
            );
            expect(c.find('.card').classes()).toContain('h-full');
        }
    });

    it('garde la typographie Apollo constante : aucune taille de police ne rétrécit avec la largeur', () => {
        const wrapper = monter([
            compte({
                solde: 1_519_203_500,
                en_cours_versement: 3_000_000,
                versements_en_cours: 1,
            }),
        ]);
        const zone = wrapper.find('[data-testid="support-kpis"]');
        const enCours = carte(wrapper, 'en-cours-versement');

        // Poppins limitée à cette grille ; Apollo : titre 15,75 / 600 / 24,5 — chiffre 31,5 / 700 / 35 —
        // information secondaire 14 / 500 / 16,8.
        expect(zone.classes()).toContain('font-apollo');
        expect(enCours.find('span.font-semibold').classes()).toEqual(
            expect.arrayContaining([
                'text-[15.75px]',
                'leading-[24.5px]',
                'font-semibold',
            ]),
        );
        expect(
            enCours.find('[data-slot="kpi-value"]').element.parentElement
                ?.className,
        ).toContain('text-[31.5px] leading-[35px] font-bold');
        expect(enCours.find('[data-slot="kpi-detail"]').classes()).toEqual(
            expect.arrayContaining([
                'text-[14px]',
                'leading-[16.8px]',
                'font-medium',
            ]),
        );

        // Garde-fou : plus aucune logique qui réduit la police (clamp / unité de container query).
        expect(zone.html()).not.toContain('clamp(');
        expect(zone.html()).not.toContain('cqw');
    });

    it('ne coupe jamais un nombre au milieu : le chiffre reste insécable, seule « GNF » peut passer dessous', () => {
        const solde = carte(monter(), 'solde-total');

        expect(solde.find('[data-slot="kpi-value"]').classes()).toContain(
            'whitespace-nowrap',
        );
        const unite = solde.findAll('span').find((s) => s.text() === 'GNF');
        expect(unite?.classes()).toEqual(
            expect.arrayContaining(['inline-block', 'whitespace-nowrap']),
        );
    });

    it('espace le nombre de « GNF » côté nombre : passée à la ligne, « GNF » reste aligné à gauche', () => {
        const solde = carte(monter(), 'solde-total');
        const unite = solde.findAll('span').find((s) => s.text() === 'GNF');

        expect(solde.find('[data-slot="kpi-value"]').classes()).toContain(
            'mr-1.5',
        );
        expect(unite?.classes()).not.toContain('ml-1.5');
    });

    it('affiche des titres courts et la valeur de chaque indicateur', () => {
        const wrapper = monter();

        expect(carte(wrapper, 'supports').text()).toContain('Supports');
        expect(valeur(wrapper, 'supports')).toBe('3');
        expect(carte(wrapper, 'caisses-dediees').text()).toContain(
            'Caisses dédiées',
        );
        expect(carte(wrapper, 'caisses-dediees').text()).not.toContain(
            'agents',
        );
        expect(valeur(wrapper, 'caisses-dediees')).toBe('1');
        expect(carte(wrapper, 'solde-total').text()).toContain('Solde total');
        expect(carte(wrapper, 'solde-total').text()).not.toContain('affichés');
        expect(valeur(wrapper, 'solde-total')).toBe('267 824 000');
        expect(carte(wrapper, 'solde-total').text()).toContain('GNF');
        expect(valeur(wrapper, 'en-cours-versement')).toBe('0');
    });

    it('ne met aucun paragraphe explicatif dans les cartes', () => {
        const texte = monter().find('[data-testid="support-kpis"]').text();

        for (const explication of [
            'Caisses, banques et Mobile Money',
            'Une caisse active par agent',
            'Vue de situation',
            'Envoyé, pas encore reçu',
            'Selon les filtres',
            'Aucun versement à confirmer',
        ]) {
            expect(texte).not.toContain(explication);
        }
    });

    it("garde le détail métier en infobulle : le solde total n'est pas le Disponible de Financement", () => {
        const wrapper = monter();

        expect(carte(wrapper, 'solde-total').attributes('title')).toContain(
            'Disponible de Financement',
        );
        expect(
            carte(wrapper, 'en-cours-versement').attributes('title'),
        ).toContain('pas encore reçu');
        expect(carte(wrapper, 'supports').attributes('title')).toBeUndefined();
    });

    it('rappelle en infobulle que la synthèse suit les filtres actifs', () => {
        expect(
            carte(monter(COMPTES, true), 'supports').attributes('title'),
        ).toBe('Selon les filtres actifs');
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

    it('lie « Approvisionner un agent » au seul indicateur serveur peut_approvisionner', () => {
        const [agentSans, agenceSans] = lignes(monter());
        expect(
            agenceSans.find('[data-testid="support-approvisionner"]').exists(),
        ).toBe(false);
        expect(
            agentSans.find('[data-testid="support-approvisionner"]').exists(),
        ).toBe(false);

        const comptes = COMPTES.map((c) =>
            c.nature === 'agence' && c.type === 'caisse'
                ? { ...c, peut_approvisionner: true }
                : c,
        );
        const [, agence] = lignes(monter(comptes));
        expect(
            agence.find('[data-testid="support-approvisionner"]').exists(),
        ).toBe(true);
    });

    it("signale sous la caisse de l'agent un approvisionnement à confirmer, hors solde", () => {
        const comptes = COMPTES.map((c) =>
            c.nature === 'dediee'
                ? {
                      ...c,
                      en_cours_approvisionnement: 2_000_000,
                      approvisionnements_en_cours: 1,
                  }
                : c,
        );
        const [agent] = lignes(monter(comptes));
        const attente = agent.get(
            '[data-testid="support-en-cours-approvisionnement"]',
        );

        expect(attente.text()).toContain("À confirmer par l'agent");
        expect(attente.text()).toContain('2');
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

    const carte = (wrapper: ReturnType<typeof monter>, id: string) =>
        wrapper.find(`[data-testid="support-kpi-${id}"]`);

    it('affiche « En cours de versement » à part du solde, avec « 1 à confirmer »', () => {
        const wrapper = monter([
            CAISSE_QUI_VERSE,
            compte({ id: 'c-agence', solde: 0 }),
        ]);
        const solde = carte(wrapper, 'solde-total');
        const enCours = carte(wrapper, 'en-cours-versement');

        // Solde ≠ en cours de versement : les 800 000 ne sont dans aucun solde.
        expect(solde.find('[data-slot="kpi-value"]').text()).toBe('50 000');
        expect(enCours.text()).toContain('En cours de versement');
        expect(enCours.find('[data-slot="kpi-value"]').text()).toBe('800 000');
        expect(enCours.find('[data-slot="kpi-detail"]').text()).toBe(
            '1 à confirmer',
        );
    });

    it('affiche 0 GNF sans information secondaire quand aucun versement n’est en cours', () => {
        const enCours = carte(monter([compte({})]), 'en-cours-versement');

        expect(enCours.find('[data-slot="kpi-value"]').text()).toBe('0');
        expect(enCours.text()).toContain('GNF');
        expect(enCours.find('[data-slot="kpi-detail"]').exists()).toBe(false);
    });

    it('compte les versements en cours de plusieurs caisses', () => {
        const wrapper = monter([
            CAISSE_QUI_VERSE,
            compte({
                id: 'c-verse-2',
                nature: 'dediee',
                agent: { id: 'u2', nom: 'Moussa' },
                solde: 0,
                en_cours_versement: 200_000,
                versements_en_cours: 2,
            }),
        ]);
        const enCours = carte(wrapper, 'en-cours-versement');

        expect(enCours.find('[data-slot="kpi-value"]').text()).toBe(
            '1 000 000',
        );
        expect(enCours.find('[data-slot="kpi-detail"]').text()).toBe(
            '3 à confirmer',
        );
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

describe('Supports de trésorerie — compte Mobile Money dédié à un agent', () => {
    type Ouvrable = { ouvrirEdition: (c: CompteTresorerie) => void };

    const MOBILE_MONEY_AGENT = compte({
        id: 'c-om-agent',
        libelle: 'Orange Money — Moussa sidibé',
        type: 'mobile_money',
        type_label: 'Mobile Money',
        operateur_mobile_money: 'orange_money',
        operateur_label: 'Orange Money',
        numero: '620 00 00 01',
        nature: 'dediee',
        agent: { id: 'u1', nom: 'Moussa sidibé' },
        compte_numero: '561100001',
        solde: 0,
    });

    beforeEach(() => {
        etat.permissions = ['tresorerie.gerer_soldes_ouverture'];
    });

    it('affiche le compte avec son opérateur, son numéro et son titulaire, sans « Verser »', () => {
        const wrapper = monter([MOBILE_MONEY_AGENT]);
        const ligne = lignes(wrapper)[0];

        expect(ligne.find('[data-testid="support-nature"]').text()).toBe(
            'Orange Money dédié',
        );
        expect(ligne.find('[data-testid="support-numero"]').text()).toContain(
            '620 00 00 01',
        );
        expect(ligne.find('[data-testid="support-compte"]').text()).toBe(
            '561100001',
        );
        expect(ligne.findAll('td')[4].text()).toBe('Moussa sidibé');
        expect(ligne.text()).not.toContain("Verser à l'agence");
    });

    it('ne le compte pas dans la carte « Caisses dédiées »', () => {
        const wrapper = monter([MOBILE_MONEY_AGENT, ...COMPTES]);
        const carte = wrapper.find(
            '[data-testid="support-kpi-caisses-dediees"]',
        );

        expect(carte.text()).toContain('1');
        expect(carte.text()).not.toContain('2');
    });

    it("demande l'opérateur et le numéro du portefeuille à la création", async () => {
        const wrapper = monter([]);

        await wrapper.find('#sup-nature').setValue('dediee');
        expect(wrapper.find('#sup-operateur-dedie').exists()).toBe(false);
        expect(wrapper.text()).toContain('Créer la caisse');

        await wrapper.find('#sup-type-dedie').setValue('mobile_money');

        expect(wrapper.find('#sup-operateur-dedie').exists()).toBe(true);
        expect(wrapper.find('#sup-numero-dedie').exists()).toBe(true);
        expect(wrapper.text()).toContain(
            "Les encaissements Mobile Money de l'agent arriveront sur ce compte",
        );
        expect(wrapper.text()).toContain('Créer le compte');
        // Le choix d'un compte comptable reste réservé aux supports de l'agence.
        expect(wrapper.find('#sup-compte').exists()).toBe(false);
    });

    it('laisse corriger le numéro en brouillon et le fige après la validation', async () => {
        const ouvrir = async (c: CompteTresorerie) => {
            const wrapper = monter([c]);
            (wrapper.vm as unknown as Ouvrable).ouvrirEdition(c);
            await nextTick();

            return wrapper;
        };

        const brouillon = await ouvrir(
            compte({
                ...MOBILE_MONEY_AGENT,
                actif: false,
                statut: 'brouillon',
                statut_label: 'Brouillon',
                valide_le: null,
            }),
        );
        expect(
            brouillon.find('[data-testid="edit-mobile-money-dedie"]').text(),
        ).toContain('Orange Money');
        // Le champ factice rend l'attribut en toutes lettres : « false » = modifiable.
        expect(
            brouillon.find('#edit-numero-dedie').attributes('disabled'),
        ).toBe('false');

        const valide = await ouvrir(MOBILE_MONEY_AGENT);
        expect(valide.find('#edit-numero-dedie').attributes('disabled')).toBe(
            'true',
        );
        expect(valide.text()).toContain(
            "Le numéro d'un compte validé ne peut plus être modifié",
        );
        expect(
            valide.find('[data-testid="edit-solde-ouverture"]').exists(),
        ).toBe(false);
    });
});

describe("Supports de trésorerie — transfert Mobile Money vers l'agence", () => {
    type Ouvrable = { ouvrirTransfert: (c: CompteTresorerie) => void };
    type AvecFormulaire = { transfertForm: { reference_externe: string } };

    const COMPTE_AGENT = compte({
        id: 'c-om-agent',
        libelle: 'Orange Money — Moussa sidibé',
        type: 'mobile_money',
        type_label: 'Mobile Money',
        operateur_mobile_money: 'orange_money',
        operateur_label: 'Orange Money',
        nature: 'dediee',
        agent: { id: 'u1', nom: 'Moussa sidibé' },
        compte_numero: '561100001',
        solde: 500_000,
        peut_transferer: true,
    });

    const DESTINATIONS = [
        {
            id: 'd-om',
            site_id: 's1',
            libelle: 'Orange Money de Matoto',
            numero: '611 00 00 00',
            operateur_mobile_money: 'orange_money',
        },
        {
            id: 'd-momo',
            site_id: 's1',
            libelle: 'MoMo de Matoto',
            numero: null,
            operateur_mobile_money: 'momo',
        },
        {
            id: 'd-kouria',
            site_id: 's2',
            libelle: 'Orange Money de Kouria',
            numero: null,
            operateur_mobile_money: 'orange_money',
        },
    ];

    const ouvrir = async () => {
        const wrapper = monter([COMPTE_AGENT], false, {
            destinations_transfert: DESTINATIONS,
        });
        (wrapper.vm as unknown as Ouvrable).ouvrirTransfert(COMPTE_AGENT);
        await nextTick();

        return wrapper;
    };

    const saisir = async (
        wrapper: Awaited<ReturnType<typeof ouvrir>>,
        testid: string,
        valeur: string,
    ) => {
        const champ = wrapper.find(`[data-testid="${testid}"]`);
        (champ.element as HTMLInputElement).value = valeur;
        await champ.trigger('input');
    };

    const envoyer = (wrapper: Awaited<ReturnType<typeof ouvrir>>) =>
        wrapper.find('[data-testid="transfert-envoyer"]');

    beforeEach(() => {
        etat.permissions = [];
    });

    it("propose « Transférer à l'agence » selon le seul indicateur serveur peut_transferer", () => {
        const [agent, sans] = lignes(
            monter([
                COMPTE_AGENT,
                compte({ ...COMPTE_AGENT, id: 'c-2', peut_transferer: false }),
            ]),
        );

        expect(agent.find('[data-testid="support-transferer"]').exists()).toBe(
            true,
        );
        expect(agent.find('[data-testid="support-verser"]').exists()).toBe(
            false,
        );
        expect(sans.find('[data-testid="support-transferer"]').exists()).toBe(
            false,
        );
    });

    it("ne propose que les comptes de l'agence du même opérateur, et présélectionne le seul possible", async () => {
        const wrapper = await ouvrir();
        const options = wrapper.findAll('#transfert-dest option');

        expect(options.map((o) => o.attributes('value'))).toEqual(['', 'd-om']);
        expect(options[1].text()).toContain('Orange Money de Matoto');
        expect(options[1].text()).toContain('611 00 00 00');
        expect(
            (wrapper.find('#transfert-dest').element as HTMLSelectElement)
                .value,
        ).toBe('d-om');
    });

    it("exige les frais, même nuls, et la référence avant d'activer l'envoi", async () => {
        const wrapper = await ouvrir();

        await saisir(wrapper, 'transfert-montant', '495000');
        expect(envoyer(wrapper).attributes('disabled')).toBeDefined();

        // Frais saisis à 0 : acceptés, mais la référence manque encore.
        await saisir(wrapper, 'transfert-frais', '0');
        expect(envoyer(wrapper).attributes('disabled')).toBeDefined();

        (
            wrapper.vm as unknown as AvecFormulaire
        ).transfertForm.reference_externe = 'OM-TR-001';
        await nextTick();
        expect(envoyer(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('montre ce qui sort du compte : montant reçu par l’agence plus frais', async () => {
        const wrapper = await ouvrir();

        await saisir(wrapper, 'transfert-montant', '495000');
        await saisir(wrapper, 'transfert-frais', '5000');

        expect(wrapper.find('[data-testid="transfert-sortie"]').text()).toMatch(
            /500\s000/,
        );
        expect(
            wrapper.find('[data-testid="transfert-depassement"]').exists(),
        ).toBe(false);

        await saisir(wrapper, 'transfert-frais', '5001');
        expect(
            wrapper.find('[data-testid="transfert-depassement"]').exists(),
        ).toBe(true);
        expect(envoyer(wrapper).attributes('disabled')).toBeDefined();
    });

    it('rend visible une erreur serveur sur le motif facultatif replié', async () => {
        const wrapper = await ouvrir();
        const form = wrapper.get('[data-testid="transfert-form"]');
        const details = form.get('details');
        expect((details.element as HTMLDetailsElement).open).toBe(false);

        const { transfertForm } = wrapper.vm as unknown as {
            transfertForm: { motif: string; errors: { motif?: string } };
        };
        transfertForm.motif = 'Versement du jour';
        transfertForm.errors.motif = 'Le motif est trop long.';
        await nextTick();

        expect((details.element as HTMLDetailsElement).open).toBe(true);
        expect(details.text()).toContain('Le motif est trop long.');
        expect(transfertForm.motif).toBe('Versement du jour');
    });

    it('nomme « En cours de transfert » le montant envoyé par un compte Mobile Money', () => {
        const ligne = lignes(
            monter([compte({ ...COMPTE_AGENT, en_cours_versement: 300_000 })]),
        )[0];

        expect(
            ligne.find('[data-testid="support-en-cours-versement"]').text(),
        ).toContain('En cours de transfert');
    });
});

describe("Supports de trésorerie — suppression d'un brouillon", () => {
    type Suppression = {
        ouvrirSuppression: (c: CompteTresorerie) => void;
        supprimerSupport: () => void;
        suppressionVisible: boolean;
    };

    const BROUILLON_AGENT = compte({
        ...BROUILLON,
        id: 'c-doublon',
        libelle: 'Caisse Dalanda CONDÉ (2)',
        nature: 'dediee',
        agent: { id: 'u9', nom: 'Dalanda CONDÉ' },
        compte_numero: '571011',
        peut_valider: false,
        peut_supprimer: true,
    });

    const ouvrir = async (c: CompteTresorerie = BROUILLON_AGENT) => {
        const wrapper = monter([c]);
        (wrapper.vm as unknown as Suppression).ouvrirSuppression(c);
        await nextTick();

        return wrapper;
    };

    beforeEach(() => {
        etat.permissions = [];
        vi.mocked(router.delete).mockReset();
    });

    it('propose « Supprimer le brouillon » selon le seul indicateur serveur, même sans gérer les supports', () => {
        const [avec, sans] = lignes(
            monter([
                BROUILLON_AGENT,
                compte({
                    ...BROUILLON_AGENT,
                    id: 'c-3',
                    peut_supprimer: false,
                }),
            ]),
        );

        expect(avec.find('[data-testid="support-supprimer"]').exists()).toBe(
            true,
        );
        expect(sans.find('[data-testid="support-supprimer"]').exists()).toBe(
            false,
        );
        expect(sans.find('[data-testid="support-actions"]').exists()).toBe(
            false,
        );
    });

    it('ne propose jamais la suppression pour un support validé', () => {
        etat.permissions = ['tresorerie.gerer_soldes_ouverture'];
        const [actif, inactif] = lignes(
            monter([
                compte({ peut_supprimer: true }),
                compte({
                    id: 'c-4',
                    actif: false,
                    statut: 'inactif',
                    statut_label: 'Inactif',
                    peut_supprimer: true,
                }),
            ]),
        );

        expect(actif.find('[data-testid="support-supprimer"]').exists()).toBe(
            false,
        );
        expect(inactif.find('[data-testid="support-supprimer"]').exists()).toBe(
            false,
        );
    });

    it('rappelle le support, son agence et son responsable, puis envoie le motif', async () => {
        const wrapper = await ouvrir();
        const formulaire = wrapper.find('[data-testid="suppression-form"]');

        expect(formulaire.text()).toContain('Caisse Dalanda CONDÉ (2)');
        expect(formulaire.text()).toContain('Matoto');
        expect(formulaire.text()).toContain('Dalanda CONDÉ');
        expect(formulaire.text()).toContain('571011');
        expect(formulaire.text()).toContain('avec son sous-compte comptable');

        wrapper
            .findAllComponents({ name: 'Input' })
            .find((c) => c.attributes('id') === 'suppression-motif')
            ?.vm.$emit('update:modelValue', '  Doublon créé par erreur  ');
        await nextTick();
        await formulaire.trigger('submit');

        expect(router.delete).toHaveBeenCalledTimes(1);
        const [url, options] = vi.mocked(router.delete).mock.calls[0];
        expect(url).toBe(
            '/backoffice/comptabilite/tresorerie/supports/c-doublon',
        );
        expect(options?.data).toEqual({ motif: 'Doublon créé par erreur' });
    });

    it('envoie un motif vide comme absent', async () => {
        const wrapper = await ouvrir();

        await wrapper
            .find('[data-testid="suppression-form"]')
            .trigger('submit');

        expect(vi.mocked(router.delete).mock.calls[0][1]?.data).toEqual({
            motif: null,
        });
    });

    it('ignore un second envoi et garde la fenêtre ouverte pendant la requête', async () => {
        const wrapper = await ouvrir();
        const vm = wrapper.vm as unknown as Suppression;

        vm.supprimerSupport();
        vm.supprimerSupport();
        expect(router.delete).toHaveBeenCalledTimes(1);

        vm.suppressionVisible = false;
        await nextTick();
        expect(wrapper.find('[data-testid="suppression-form"]').exists()).toBe(
            true,
        );
        const confirmer = wrapper.find('[data-testid="suppression-confirmer"]');
        expect(confirmer.attributes('disabled')).toBeDefined();
        expect(confirmer.text()).toContain('Suppression…');

        const options = vi.mocked(router.delete).mock.calls[0][1];
        options?.onSuccess?.({} as never);
        options?.onFinish?.({} as never);
        await nextTick();
        expect(wrapper.find('[data-testid="suppression-form"]').exists()).toBe(
            false,
        );
    });
});
