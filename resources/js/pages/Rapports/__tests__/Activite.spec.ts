import DataFilters from '@/components/filters/DataFilters.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import Activite from '@/pages/Rapports/Activite.vue';
import ListeFactures from '@/pages/Rapports/partials/ListeFactures.vue';
import type { RapportActivite } from '@/types/rapports';
import { router } from '@inertiajs/vue3';
import { mount, shallowMount } from '@vue/test-utils';
import Select from 'primevue/select';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const url = vi.hoisted(() => ({
    valeur: '/backoffice/ma-situation?tab=caisse',
}));

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
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            props: { href: String },
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { ...attrs, href: props.href }, slots.default?.()),
        }),
        router: { replace: vi.fn(), get: vi.fn() },
        usePage: () => ({
            url: url.valeur,
            component: 'Rapports/Activite',
            props: { auth: { user_sites: [{ id: 's1', nom: 'Matoto' }] } },
        }),
    };
});

const rapportVide = (): RapportActivite => ({
    ventes: {
        resume: {
            nombre: 0,
            facture: 0,
            encaisse: 0,
            reste: 0,
            annulees_nombre: 0,
            annulees_montant: 0,
        },
        lignes: [],
        total_lignes: 0,
    },
    encaissements: {
        resume: {
            nombre: 0,
            montant: 0,
            pour_autres_agences_nombre: 0,
            pour_autres_agences_montant: 0,
        },
        par_moyen: [],
        lignes: [],
        total_lignes: 0,
    },
    creances: {
        resume: {
            nombre: 0,
            impayees: 0,
            partielles: 0,
            facture: 0,
            reste: 0,
            plus_ancienne: null,
        },
        lignes: [],
        total_lignes: 0,
    },
    mobile_money: {
        resume: {
            nombre: 0,
            montant: 0,
            reference_absente: 0,
            reference_dupliquee: 0,
            anterieure_obligation: 0,
        },
        par_operateur: [],
        lignes: [],
        total_lignes: 0,
    },
    caisse: {
        aucune_caisse: true,
        detail: true,
        resume: {
            solde_debut: 0,
            entrees: 0,
            sorties: 0,
            solde_fin: 0,
            solde_actuel: 0,
            en_cours_montant: 0,
            en_cours_nombre: 0,
            contestes_nombre: 0,
        },
        fiches: [],
    },
    moyens: [
        { cle: 'especes', libelle: 'Espèces' },
        { cle: 'mobile_money:orange_money', libelle: 'Orange Money' },
    ],
    charges: {
        resume: {
            charges_saisies: 0,
            charges_payees: 0,
            charges_payees_nombre: 0,
            charges_en_attente: 0,
            charges_en_attente_nombre: 0,
            charges_avances_tiers: 0,
        },
        lignes: [],
        total_lignes: 0,
    },
    remise: null,
    agents: null,
});

// Composants propres à la page rendus pour de vrai (cartes, sections, listes) ; seuls
// DataFilters et AppLayout restent des bouchons.
const composantsReels = {
    KpiCard: false,
    ListPageActions: false,
    CarteSection: false,
    EnTeteSection: false,
    ListeFactures: false,
    ListeEncaissements: false,
    ListeCharges: false,
    TableauAgents: false,
    SyntheseRemise: false,
    StatusDot: false,
    SectionCaisse: false,
    CaisseFiche: false,
};

const monter = (
    mode: 'ma_situation' | 'rapport',
    rapport: RapportActivite = rapportVide(),
    peutVoirDepenses = false,
) =>
    shallowMount(Activite, {
        props: {
            mode,
            url:
                mode === 'ma_situation'
                    ? '/backoffice/ma-situation'
                    : '/backoffice/rapports/activite',
            export_url: '/backoffice/ma-situation/export',
            filters: {
                periode: 'aujourd_hui',
                date_debut: null,
                date_fin: null,
                site_ids: [],
                agent_id: null,
            },
            periode: {
                cle: 'aujourd_hui',
                date_debut: '2026-09-26',
                date_fin: '2026-09-26',
                libelle: "Aujourd'hui (26/09/2026)",
                options: [
                    { value: 'aujourd_hui', label: "Aujourd'hui" },
                    { value: 'hier', label: 'Hier' },
                    { value: 'personnalisee', label: 'Période personnalisée' },
                ],
            },
            sites: [],
            agents: [{ value: 'u1', label: 'Moussa Sidibé' }],
            agent:
                mode === 'ma_situation'
                    ? { id: 'u1', nom: 'Moussa Sidibé' }
                    : null,
            limite_lignes: 300,
            rapport,
            peut_voir_depenses: peutVoirDepenses,
        },
        global: { renderStubDefaultSlot: true, stubs: composantsReels },
    });

describe('Rapports/Activite', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        url.valeur = '/backoffice/ma-situation?tab=caisse';
    });

    it('Ma situation affiche la période après PDF, sans drawer de filtres vide', () => {
        const wrapper = monter('ma_situation');
        expect(wrapper.findComponent(DataFilters).exists()).toBe(false);
        expect(wrapper.findComponent(Select).exists()).toBe(true);
        expect(
            wrapper
                .get('[data-testid="rapport-export-pdf"]')
                .element.nextElementSibling?.getAttribute('data-testid'),
        ).toBe('rapport-periode');
    });

    it('le panneau du rapport regroupe Agence, Agent et Période, sans sélecteur dans l’en-tête', () => {
        const wrapper = monter('rapport');
        const filtres = wrapper.findComponent(DataFilters);

        expect(
            filtres.props('fields').map((f: { key: string }) => f.key),
        ).toEqual(['agent_id', 'periode']);
        expect(filtres.props('hideAgenceSelector')).toBe(false);
        expect(filtres.props('fields')[1]).toMatchObject({
            type: 'period',
            defaultValue: 'aujourd_hui',
            options: [
                { value: 'aujourd_hui', label: "Aujourd'hui" },
                { value: 'hier', label: 'Hier' },
                { value: 'personnalisee', label: 'Période personnalisée' },
            ],
        });
        expect(wrapper.find('[data-testid="rapport-periode"]').exists()).toBe(
            false,
        );
    });

    it('transmet la période appliquée au panneau, sans la dupliquer dans les paramètres de base', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const filters = {
            periode: 'personnalisee',
            date_debut: '2026-09-01',
            date_fin: '2026-09-25',
            site_ids: ['s1'],
            agent_id: 'u1',
        };
        await wrapper.setProps({ filters });
        const filtres = wrapper.findComponent(DataFilters);

        expect(filtres.props('values')).toEqual(filters);
        expect(filtres.props('baseParams')).toEqual({ tab: 'creances' });
    });

    it('applique une plage personnalisée complète sur validation, en rejetant les dates inversées', async () => {
        const wrapper = monter('ma_situation');
        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', 'personnalisee');
        await wrapper.vm.$nextTick();
        expect(router.get).not.toHaveBeenCalled();
        await wrapper
            .get('[aria-label="Date de début"]')
            .setValue('2026-09-27');
        await wrapper.get('[aria-label="Date de fin"]').setValue('2026-09-20');
        await wrapper.get('form').trigger('submit');
        expect(router.get).not.toHaveBeenCalled();
        await wrapper
            .get('[aria-label="Date de début"]')
            .setValue('2026-09-01');
        await wrapper.get('form').trigger('submit');
        expect(router.get).toHaveBeenCalledWith(
            '/backoffice/ma-situation',
            { tab: 'caisse', date_debut: '2026-09-01', date_fin: '2026-09-20' },
            expect.any(Object),
        );
    });

    it('Ma situation : une date précise envoie le même jour en début et en fin', async () => {
        const wrapper = monter('ma_situation');
        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', 'date_precise');
        await wrapper.vm.$nextTick();
        expect(router.get).not.toHaveBeenCalled();
        expect(wrapper.find('[aria-label="Date de fin"]').exists()).toBe(false);
        await wrapper
            .get('[data-testid="rapport-periode-date"]')
            .setValue('2026-09-13');
        await wrapper.get('form').trigger('submit');
        expect(router.get).toHaveBeenCalledWith(
            '/backoffice/ma-situation',
            { tab: 'caisse', date_debut: '2026-09-13', date_fin: '2026-09-13' },
            expect.any(Object),
        );
    });

    it('regroupe les filtres dans le drawer et conserve la section lors du filtrage', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const filtres = wrapper.findComponent(DataFilters);
        expect(filtres.props('triggerOnly')).toBe(true);
        expect(filtres.props('baseParams')).toEqual({ tab: 'creances' });

        await wrapper
            .get('[data-testid="rapport-tab-encaissements"]')
            .trigger('click');
        expect(filtres.props('baseParams')).toEqual({ tab: 'encaissements' });
        expect(
            wrapper.get('[role="tabpanel"]').attributes('aria-labelledby'),
        ).toBe('rapport-tab-encaissements');
    });

    it('permet de parcourir les sections avec les flèches, Début et Fin', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=ventes';
        const wrapper = monter('rapport');
        await wrapper
            .get('#rapport-tab-ventes')
            .trigger('keydown', { key: 'ArrowRight' });
        expect(
            wrapper
                .get('#rapport-tab-encaissements')
                .attributes('aria-selected'),
        ).toBe('true');
        expect(
            wrapper.get('#rapport-tab-encaissements').attributes('tabindex'),
        ).toBe('0');
        expect(wrapper.get('#rapport-tab-ventes').attributes('tabindex')).toBe(
            '-1',
        );
        await wrapper
            .get('#rapport-tab-encaissements')
            .trigger('keydown', { key: 'End' });
        expect(
            wrapper.get('#rapport-tab-synthese').attributes('aria-selected'),
        ).toBe('true');
        await wrapper
            .get('#rapport-tab-synthese')
            .trigger('keydown', { key: 'Home' });
        expect(
            wrapper.get('#rapport-tab-ventes').attributes('aria-selected'),
        ).toBe('true');
        await wrapper
            .get('#rapport-tab-ventes')
            .trigger('keydown', { key: 'ArrowLeft' });
        expect(
            wrapper.get('#rapport-tab-synthese').attributes('aria-selected'),
        ).toBe('true');
    });

    it('les dettes clients suivent la période du filtre global', () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const panneau = wrapper.get('[role="tabpanel"]');
        expect(panneau.text()).toContain("Aujourd'hui (26/09/2026)");
        expect(panneau.text()).not.toContain('Toutes dates');
        expect(
            wrapper.get('[data-testid="rapport-tab-creances"]').text(),
        ).not.toContain('toutes dates');
        const aide = panneau.findComponent(InfoTooltip);
        expect(aide.text()).toContain('Factures créées sur la période');
        expect(aide.props('label')).toBe('Comprendre : Dettes clients');
    });

    it('sans caisse dédiée : un message, jamais un solde à zéro', () => {
        const wrapper = monter('ma_situation');

        expect(wrapper.get('[data-testid="kpi-caisse"]').text()).toBe(
            'Aucune caisse dédiée',
        );
        expect(wrapper.find('[data-testid="caisse-aucune"]').exists()).toBe(
            true,
        );
        expect(wrapper.get('#rapport-tab-ventes').attributes('tabindex')).toBe(
            '0',
        );
    });

    it('parle de « Dettes clients », jamais de « créances », dans l’interface', () => {
        url.valeur = '/backoffice/ma-situation?tab=creances';
        const wrapper = monter('ma_situation');

        expect(
            wrapper.get('[data-testid="rapport-tab-creances"]').text(),
        ).toContain('Dettes clients');
        expect(wrapper.text()).toContain('Aucune dette client sur la période.');
        expect(wrapper.text()).not.toMatch(/créance/i);

        url.valeur = '/backoffice/ma-situation?tab=ventes';
        expect(monter('rapport').text()).not.toMatch(/créance/i);
    });

    it("les exports reprennent les filtres de l'écran, sans l'onglet", () => {
        url.valeur = '/backoffice/ma-situation?periode=hier&tab=caisse';
        const wrapper = monter('ma_situation');

        expect(
            wrapper
                .get('[data-testid="rapport-export-pdf"]')
                .attributes('href'),
        ).toBe('/backoffice/ma-situation/export?periode=hier&format=pdf');
    });

    it('en-tête : identité et agence pour Ma situation, périmètre en clair pour le rapport', () => {
        url.valeur = '/backoffice/ma-situation';
        expect(
            monter('ma_situation')
                .get('[data-testid="rapport-perimetre"]')
                .text(),
        ).toBe('Moussa Sidibé · Matoto');

        url.valeur = '/backoffice/rapports/activite';
        expect(
            monter('rapport').get('[data-testid="rapport-perimetre"]').text(),
        ).toBe('Toutes les agences · Tous les agents');
    });

    it('les cartes sont les onglets : une seule carte active, reconnaissable sans la couleur', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const cartes = wrapper.findAll('[role="tab"]');

        expect(cartes.map((c) => c.attributes('data-testid'))).toEqual([
            'rapport-tab-ventes',
            'rapport-tab-encaissements',
            'rapport-tab-creances',
            'rapport-tab-mobile_money',
            'rapport-tab-caisse',
            'rapport-tab-charges',
            'rapport-tab-synthese',
        ]);
        const actives = cartes.filter(
            (c) => c.attributes('aria-selected') === 'true',
        );
        expect(actives).toHaveLength(1);
        expect(actives[0].text()).toContain('(détail affiché)');
        expect(wrapper.text()).not.toContain('Encaissé sur ces ventes');
    });

    it('sur téléphone, les lignes sont une liste empilée et le tableau est réservé aux écrans larges', () => {
        const wrapper = mount(ListeFactures, {
            props: {
                afficherAgent: false,
                vide: 'Aucune vente.',
                lignes: [
                    {
                        id: 'f1',
                        reference: 'FAC-001',
                        date: '2026-09-26',
                        client: 'Client A',
                        agent: null,
                        vehicule_nom: null,
                        site_nom: 'Matoto',
                        encaisse_a: null,
                        montant: 800_000,
                        encaisse: 550_000,
                        reste: 250_000,
                        statut: 'partiel',
                        statut_label: 'Partiellement payée',
                    },
                ],
            },
        });

        const liste = wrapper.get('[data-testid="liste-mobile"]');
        expect(liste.classes()).toContain('sm:hidden');
        expect(liste.text()).toContain('FAC-001');
        expect(liste.text()).toContain('Reste à payer');
        expect(wrapper.get('table').element.parentElement?.className).toContain(
            'hidden',
        );
    });

    // ADR 0012 : l'agent remet tout à la caisse de SON agence ; le reversement à l'agence de la
    // commande est une obligation de l'agence (Trésorerie → Inter-agences), jamais de l'agent.
    const rapportPourAutreAgence = (): RapportActivite => {
        const rapport = rapportVide();
        rapport.encaissements.resume = {
            nombre: 1,
            montant: 10_800_000,
            pour_autres_agences_nombre: 1,
            pour_autres_agences_montant: 10_800_000,
        };
        rapport.encaissements.par_moyen = [
            {
                cle: 'especes',
                libelle: 'Espèces',
                mode_paiement: 'especes',
                nombre: 1,
                montant: 10_800_000,
            },
        ];
        rapport.encaissements.lignes = [
            {
                id: 'e1',
                date_encaissement: '2026-09-29',
                saisi_le: '2026-09-29 10:00',
                saisie_differee: false,
                montant: 10_800_000,
                mode_paiement: 'especes',
                operateur_mobile_money: null,
                moyen_libelle: 'Espèces',
                reference_paiement: null,
                facture_id: 'f1',
                facture_reference: 'VTE-290926-001',
                client: null,
                agent: 'Moussa Sidibé',
                site_nom: 'Matoto',
                encaisse_a: 'CBA',
                pour_autre_agence: true,
            },
        ];
        rapport.encaissements.total_lignes = 1;

        return rapport;
    };

    it('Ma situation ne montre jamais à l’agent ce que son agence doit reverser à une autre', () => {
        url.valeur = '/backoffice/ma-situation?tab=encaissements';
        const wrapper = monter('ma_situation', rapportPourAutreAgence());

        expect(wrapper.text()).toContain('CBA');
        expect(wrapper.text()).not.toContain('à reverser');
        expect(
            wrapper
                .find('[data-testid="encaissement-pour-autre-agence"]')
                .exists(),
        ).toBe(false);
    });

    it('le rapport d’activité des responsables garde le reversement dû par l’agence', () => {
        url.valeur = '/backoffice/rapports/activite?tab=encaissements';
        const wrapper = monter('rapport', rapportPourAutreAgence());

        expect(wrapper.text()).toContain("Pour d'autres agences (à reverser)");
        expect(
            wrapper
                .get('[data-testid="encaissement-pour-autre-agence"]')
                .text(),
        ).toBe('pour Matoto · à reverser');
    });
});

describe('Rapports/Activite — compte Mobile Money de l’agent (ADR 0027)', () => {
    const ficheMobileMoney = () => ({
        caisse: {
            id: 'mm1',
            libelle: 'Orange Money — Moussa Sidibé',
            actif: true,
            site_id: 's1',
            site_nom: 'Matoto',
            agent_id: 'u1',
            agent_nom: 'Moussa Sidibé',
            type: 'mobile_money',
            operateur_label: 'Orange Money',
        },
        solde_debut: 0,
        solde_fin: 198_000,
        total_entrees: 500_000,
        total_sorties: 302_000,
        mouvements: [
            {
                categorie: 'encaissements',
                libelle: 'Encaissements Mobile Money',
                entrees: 500_000,
                sorties: 0,
                nombre: 1,
            },
            {
                categorie: 'versements_envoyes',
                libelle: "Transferts envoyés à l'agence",
                entrees: 0,
                sorties: 300_000,
                nombre: 1,
            },
            {
                categorie: 'frais_transfert',
                libelle: 'Frais de transfert',
                entrees: 0,
                sorties: 2_000,
                nombre: 1,
            },
        ],
        solde_actuel: 198_000,
        en_cours: { nombre: 1, montant: 300_000 },
        contestes: { nombre: 0, montant: 0 },
        versements_periode: [],
        dernier_versement: null,
        ecritures: null,
    });

    const avecCompte = (aucun = false): RapportActivite => {
        const rapport = rapportVide();
        rapport.comptes_mobile_money = {
            ...rapport.caisse,
            aucune_caisse: aucun,
            fiches: aucun ? [] : [ficheMobileMoney()],
        };

        return rapport;
    };

    it("affiche dans l'onglet Mobile Money ce que l'agent détient et ce qu'il a transféré", () => {
        url.valeur = '/backoffice/ma-situation?tab=mobile_money';
        const wrapper = monter('ma_situation', avecCompte());
        const section = wrapper.find('[data-testid="comptes-mobile-money"]');

        expect(section.exists()).toBe(true);
        expect(section.text()).toContain('Mon compte Mobile Money');
        expect(section.text()).toContain("À transférer à l'agence");
        expect(section.text()).toContain('En cours de transfert');
        expect(section.text()).toContain("à confirmer par l'agence");
        expect(section.text()).toContain('Aucun transfert enregistré.');
        expect(section.text()).not.toContain('versement');
    });

    it('parle de transfert, pas de remise, dans la vue agence', () => {
        url.valeur = '/backoffice/rapports/activite?tab=mobile_money';
        const rapport = avecCompte();
        rapport.comptes_mobile_money!.detail = false;
        const section = monter('rapport', rapport).find(
            '[data-testid="comptes-mobile-money"]',
        );

        expect(section.text()).toContain('Comptes Mobile Money des agents');
        expect(section.text()).toContain('À transférer');
        expect(section.text()).toContain('Dernier transfert');
        expect(section.text()).toContain('de son compte Mobile Money');
        expect(section.text()).not.toContain('À remettre');
        expect(section.text()).not.toContain('sa caisse');
    });

    it("dit à l'agent sans compte qu'il ne peut pas encaisser en Mobile Money, et ne montre rien dans la vue agence", () => {
        url.valeur = '/backoffice/ma-situation?tab=mobile_money';
        expect(
            monter('ma_situation', avecCompte(true))
                .find('[data-testid="comptes-mobile-money"]')
                .text(),
        ).toContain('vous ne pouvez pas encaisser en Mobile Money');

        url.valeur = '/backoffice/rapports/activite?tab=mobile_money';
        expect(
            monter('rapport', avecCompte(true))
                .find('[data-testid="comptes-mobile-money"]')
                .exists(),
        ).toBe(false);
    });
});

describe('Rapports/Activite — cartes Charges et Détail (ADR 0064)', () => {
    // Exemple du métier : 1 000 000 encaissés − 200 000 de charges validées = 800 000 nets ;
    // − 500 000 déjà remis = 300 000 restants, dont 100 000 en transit.
    const remise = {
        depuis: '2026-09-01',
        encaisse: 1_000_000,
        approvisionnements: 0,
        charges_validees: 200_000,
        net_a_remettre: 800_000,
        deja_remis: 500_000,
        remis: 300_000,
        en_transit: 100_000,
        reste_a_remettre: 300_000,
        caisse_active: true,
        agences: ['Matoto'],
        derniere_remise: '2026-09-26',
        statut: 'remise_en_cours',
        statut_label: 'Remise en cours',
    };

    const avecSynthese = (): RapportActivite => {
        const rapport = rapportVide();
        rapport.remise = remise;
        rapport.charges = {
            resume: {
                charges_saisies: 250_000,
                charges_payees: 200_000,
                charges_payees_nombre: 1,
                charges_en_attente: 50_000,
                charges_en_attente_nombre: 1,
                charges_avances_tiers: 0,
            },
            lignes: [
                {
                    id: 'd1',
                    date: '2026-09-26',
                    type: 'Carburant',
                    categorie: 'Interne',
                    avance_tiers: false,
                    commentaire: null,
                    site_nom: 'Matoto',
                    agent: 'Moussa Sidibé',
                    montant: 200_000,
                    statut: 'valide',
                    statut_label: 'Validé',
                },
            ],
            total_lignes: 2,
        };
        rapport.agents = {
            lignes: [
                {
                    agent_id: 'u1',
                    agent: 'Moussa Sidibé',
                    agences: ['Matoto'],
                    ventes_nombre: 1,
                    ventes_montant: 900_000,
                    par_moyen: {
                        especes: {
                            cle: 'especes',
                            montant: 1_000_000,
                            nombre: 1,
                        },
                    },
                    encaisse_nombre: 1,
                    encaisse_montant: 1_000_000,
                    pour_autres_agences: 0,
                    dettes_nombre: 0,
                    dettes_montant: 0,
                    ...rapport.charges.resume,
                    remise,
                },
            ],
            totaux: {
                ventes_nombre: 1,
                ventes_montant: 900_000,
                par_moyen: { especes: 1_000_000 },
                encaisse_montant: 1_000_000,
                pour_autres_agences: 0,
                dettes_montant: 0,
                charges_saisies: 250_000,
                charges_payees: 200_000,
                charges_en_attente: 50_000,
                net_a_remettre: 800_000,
                deja_remis: 500_000,
                remis: 300_000,
                en_transit: 100_000,
                reste_a_remettre: 300_000,
            },
        };

        return rapport;
    };

    beforeEach(() => vi.clearAllMocks());

    it('carte Charges : les validées, et les saisies en attente à part', () => {
        url.valeur = '/backoffice/ma-situation';
        const carte = monter('ma_situation', avecSynthese()).get(
            '[data-testid="rapport-tab-charges"]',
        );

        expect(carte.text()).toContain('Mes charges');
        expect(carte.text()).toContain('200 000');
        expect(carte.text()).toContain('1 validée');
        expect(carte.text()).toContain('50 000 GNF en attente (1)');
    });

    it('onglet Charges : liste, lien vers la dépense seulement si son écran est ouvert', () => {
        url.valeur = '/backoffice/ma-situation?tab=charges';
        const sansLien = monter('ma_situation', avecSynthese());
        expect(sansLien.text()).toContain('Carburant');
        expect(sansLien.find('[data-testid="charge-detail"]').exists()).toBe(
            false,
        );

        const avecLien = monter('ma_situation', avecSynthese(), true);
        expect(
            avecLien.get('[data-testid="charge-detail"]').attributes('href'),
        ).toBe('/backoffice/depenses/d1');
    });

    it('carte Détail : affiche le tableau consolidé au clic et le replie au second clic', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=ventes';
        const wrapper = monter('rapport', avecSynthese());
        const carte = () => wrapper.get('[data-testid="rapport-tab-synthese"]');

        expect(carte().text()).toContain('Détail des situations des agents');
        expect(carte().text()).toContain('cliquer pour afficher');
        expect(
            wrapper.find('[data-testid="rapport-agents-tableau"]').exists(),
        ).toBe(false);

        await carte().trigger('click');
        expect(
            wrapper.find('[data-testid="rapport-agents-tableau"]').exists(),
        ).toBe(true);
        expect(carte().attributes('aria-selected')).toBe('true');
        expect(carte().text()).toContain('cliquer pour replier');
        const ligne = wrapper.get('[data-testid="rapport-agent-ligne"]');
        expect(ligne.get('[data-testid="rapport-agent-net"]').text()).toBe(
            '800 000',
        );
        expect(
            ligne.get('[data-testid="rapport-agent-reste"]').text(),
        ).toContain('300 000');
        expect(ligne.text()).toContain('Remise en cours');
        expect(
            ligne
                .get('[data-testid="rapport-agent-detail"]')
                .attributes('href'),
        ).toBe('/backoffice/rapports/activite?tab=synthese&agent_id=u1');

        await carte().trigger('click');
        expect(
            wrapper.find('[data-testid="rapport-agents-tableau"]').exists(),
        ).toBe(false);
        expect(
            wrapper
                .get('[data-testid="rapport-tab-ventes"]')
                .attributes('aria-selected'),
        ).toBe('true');
    });

    it('Ma situation : le détail montre ma ligne, sans lien, et le calcul de ma remise', () => {
        url.valeur = '/backoffice/ma-situation?tab=synthese';
        const wrapper = monter('ma_situation', avecSynthese());

        expect(wrapper.text()).toContain('Détail de ma situation');
        expect(
            wrapper.find('[data-testid="rapport-agent-detail"]').exists(),
        ).toBe(false);
        expect(wrapper.get('[data-testid="ma-remise-charges"]').text()).toBe(
            '200 000',
        );
        expect(wrapper.get('[data-testid="ma-remise-net"]').text()).toBe(
            '800 000',
        );
        expect(wrapper.get('[data-testid="ma-remise-remis"]').text()).toBe(
            '500 000',
        );
        expect(wrapper.get('[data-testid="ma-remise-reste"]').text()).toBe(
            '300 000',
        );
        expect(wrapper.get('[data-testid="ma-remise"]').text()).toContain(
            'Ma remise à Matoto',
        );
    });

    it('les cartes actuelles restent en place, Charges et Détail ajoutées à la suite', () => {
        url.valeur = '/backoffice/ma-situation';
        const ids = monter('ma_situation', avecSynthese())
            .findAll('[role="tab"]')
            .map((c) => c.attributes('data-testid'));

        expect(ids).toEqual([
            'rapport-tab-ventes',
            'rapport-tab-encaissements',
            'rapport-tab-creances',
            'rapport-tab-mobile_money',
            'rapport-tab-charges',
            'rapport-tab-synthese',
        ]);
    });
});
