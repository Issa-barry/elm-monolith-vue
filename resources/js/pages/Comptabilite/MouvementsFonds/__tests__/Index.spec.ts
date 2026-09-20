import StatusDot from '@/components/StatusDot.vue';
import DataFilters from '@/components/filters/DataFilters.vue';
import { Dialog } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import MouvementsIndex from '@/pages/Comptabilite/MouvementsFonds/Index.vue';
import { router } from '@inertiajs/vue3';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

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
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        router: { post: vi.fn(), get: vi.fn() },
    };
});

const mouvement = (surcharge: Record<string, unknown> = {}) => ({
    id: 'm1',
    reference: 'MVT-2026-00001',
    nature: 'interne_caisses',
    nature_label: 'Versement de caisse',
    commentaire: null,
    site_origine: 'Matoto',
    site_destination: 'Matoto',
    site_destination_id: 's1',
    compte_origine: 'Caisse Saa Fodé',
    compte_destination: 'Caisse principale',
    compte_destination_id: 'c-agence',
    montant: 800_000,
    statut: 'envoye',
    statut_label: 'Envoyé',
    date_envoi: '2026-09-20',
    date_reception: null,
    expediteur: 'Issa BARRY',
    receptionnaire: null,
    created_at: '2026-09-20T10:00:00Z',
    peut_envoyer: false,
    peut_recevoir: false,
    peut_annuler: false,
    peut_contester: false,
    peut_confirmer_retour: false,
    ...surcharge,
});

const monter = (
    mouvements: ReturnType<typeof mouvement>[],
    surcharge: Record<string, unknown> = {},
) =>
    shallowMount(MouvementsIndex, {
        props: {
            mouvements: { data: mouvements, total: mouvements.length },
            filters: {
                statut: '',
                nature: '',
                search: '',
                site_ids: [],
                site_origine_id: '',
                site_destination_id: '',
                montant_min: '',
                montant_max: '',
            },
            statut_options: [],
            nature_options: [],
            sites: [],
            sites_mouvements: [],
            is_admin: true,
            peut_creer: false,
            comptes_tresorerie: [],
            ...surcharge,
        },
        global: {
            renderStubDefaultSlot: true,
            // Le stub par défaut ne rend que le slot par défaut : on veut aussi #filters et #primary.
            stubs: {
                ListPageActions: {
                    template:
                        '<div data-testid="list-page-actions"><slot name="filters" /><slot name="primary" /></div>',
                },
            },
        },
    });

const enAttente = (ligne: ReturnType<ReturnType<typeof monter>['find']>) =>
    ligne.find('[data-testid="mouvement-en-attente"]');

describe('Mouvements de fonds — versement en attente de confirmation', () => {
    it('reste identifiable caisse → caisse, avec son montant et son statut « Envoyé »', () => {
        const wrapper = monter([mouvement()]);
        const ligne = wrapper.find('tbody tr');

        expect(ligne.text()).toContain('Caisse Saa Fodé');
        expect(ligne.text()).toContain('Caisse principale');
        expect(ligne.text()).toContain('800 000 GNF');
        expect(ligne.findComponent(StatusDot).props('label')).toBe('Envoyé');
    });

    it('affiche « En attente de confirmation » sous le statut, à celui qui a envoyé comme à celui qui peut confirmer', () => {
        const [envoyeur, receveur] = monter([
            mouvement({ id: 'a', peut_recevoir: false }),
            mouvement({ id: 'b', peut_recevoir: true }),
        ]).findAll('tbody tr');

        expect(enAttente(envoyeur).text()).toBe('En attente de confirmation');
        expect(enAttente(receveur).text()).toBe('En attente de confirmation');
        expect(receveur.text()).toContain('Confirmer réception');
    });

    it("ne l'affiche plus une fois le versement reçu, ni pour un mouvement entre agences", () => {
        const [recu, entreAgences] = monter([
            mouvement({
                id: 'a',
                statut: 'recu',
                statut_label: 'Reçu',
                receptionnaire: 'Ibrahima Caissier',
                date_reception: '2026-09-21',
            }),
            mouvement({ id: 'b', nature: 'inter_sites' }),
        ]).findAll('tbody tr');

        expect(enAttente(recu).exists()).toBe(false);
        expect(enAttente(entreAgences).exists()).toBe(false);
    });
});

describe('Mouvements de fonds — filtres de la barre', () => {
    const sites = [
        { value: 's1', label: 'Matoto' },
        { value: 's2', label: 'Siège' },
    ];

    it('garde Référence, Nature, Origine et Destination directement dans la barre', () => {
        const champs = monter([], { sites_mouvements: sites })
            .findComponent(DataFilters)
            .props('fields');
        const parCle = Object.fromEntries(champs.map((c) => [c.key, c]));

        expect(parCle.search).toMatchObject({
            label: 'Référence',
            type: 'text',
            inline: true,
        });
        expect(parCle.nature).toMatchObject({
            label: 'Nature',
            type: 'select',
            inline: true,
        });
        expect(parCle.site_origine_id).toMatchObject({
            label: 'Origine',
            type: 'select',
            inline: true,
            options: sites,
        });
        expect(parCle.site_destination_id).toMatchObject({
            label: 'Destination',
            type: 'select',
            inline: true,
            options: sites,
        });
    });

    it('range Statut et Montant min/max dans le tiroir du bouton « Filtres » (pas dans la barre)', () => {
        const wrapper = monter([]);
        const filtres = wrapper.findComponent(DataFilters);
        const parCle = Object.fromEntries(
            filtres.props('fields').map((c) => [c.key, c]),
        );

        // Un champ sans `inline` va dans le tiroir ; DataFilters affiche alors le bouton « Filtres ».
        expect(parCle.statut.inline).toBeFalsy();
        expect(parCle.montant_min).toMatchObject({
            label: 'Montant min',
            type: 'number',
        });
        expect(parCle.montant_min.inline).toBeFalsy();
        expect(parCle.montant_max).toMatchObject({
            label: 'Montant max',
            type: 'number',
        });
        expect(parCle.montant_max.inline).toBeFalsy();
        // Pas la variante « trigger-only » : les champs `inline` restent dans la barre, seul le bouton part en en-tête.
        expect(filtres.props('triggerOnly')).toBeFalsy();
    });

    it('place le bouton « Filtres » dans l’en-tête, juste avant « Nouveau mouvement »', async () => {
        const wrapper = monter([], { peut_creer: true });
        await nextTick();

        const cible = wrapper.findComponent(DataFilters).props('triggerTarget');
        const actions = wrapper.get('[data-testid="list-page-actions"]');
        const nouveau = actions.get('[href$="/mouvements/create"]');

        expect(cible).toBeInstanceOf(HTMLElement);
        expect(actions.element.contains(cible as HTMLElement)).toBe(true);
        expect(nouveau.text()).toBe('Nouveau mouvement');
        // Ordre standard des actions d'en-tête : Filtres puis Nouveau.
        expect(
            (cible as HTMLElement).compareDocumentPosition(nouveau.element) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('garde le bouton « Filtres » dans l’en-tête même sans le droit de créer un mouvement', async () => {
        const wrapper = monter([], { peut_creer: false });
        await nextTick();

        const cible = wrapper.findComponent(DataFilters).props('triggerTarget');
        const actions = wrapper.get('[data-testid="list-page-actions"]');

        expect(actions.element.contains(cible as HTMLElement)).toBe(true);
        expect(actions.find('[href$="/mouvements/create"]').exists()).toBe(
            false,
        );
    });

    it('conserve les filtres existants, dans le même ordre, avant les nouveaux', () => {
        const cles = monter([])
            .findComponent(DataFilters)
            .props('fields')
            .map((c) => c.key);

        expect(cles).toEqual([
            'search',
            'statut',
            'nature',
            'site_origine_id',
            'site_destination_id',
            'montant_min',
            'montant_max',
        ]);
    });

    it('restitue les valeurs filtrées à la barre pour qu’elles survivent au rechargement', () => {
        const filters = {
            statut: '',
            nature: '',
            search: '',
            site_ids: [],
            site_origine_id: 's2',
            site_destination_id: 's1',
            montant_min: '200000',
            montant_max: '900000',
        };

        expect(
            monter([], { filters }).findComponent(DataFilters).props('values'),
        ).toEqual(filters);
    });
});

// Une action de trésorerie ne part qu'une fois : le backend refuse un second envoi (verrou + statut),
// mais l'interface ne doit pas laisser cliquer plusieurs fois ni fermer la fenêtre en plein envoi.
describe('Mouvements de fonds — un seul envoi à la fois', () => {
    const post = vi.mocked(router.post);

    beforeEach(() => post.mockClear());

    const bouton = (wrapper: ReturnType<typeof monter>, texte: string) =>
        wrapper.findAll('button').find((b) => b.text() === texte)!;

    const terminerRequete = async (appel = 0) => {
        (post.mock.calls[appel][2] as { onFinish: () => void }).onFinish();
        await nextTick();
    };

    const ouvrirReception = async () => {
        const wrapper = monter([mouvement({ peut_recevoir: true })]);
        await bouton(wrapper, 'Confirmer réception').trigger('click');

        return wrapper;
    };

    it('affiche un chargement sur « Confirmer » et désactive les deux boutons pendant l’envoi, puis les réactive', async () => {
        const wrapper = await ouvrirReception();
        const confirmer = wrapper.get('[data-testid="reception-confirmer"]');
        const annuler = wrapper.get('[data-testid="reception-annuler"]');

        expect(confirmer.attributes('disabled')).toBeUndefined();
        expect(confirmer.text()).toBe('Confirmer');
        expect(wrapper.findComponent(Spinner).exists()).toBe(false);

        await confirmer.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(
            '/backoffice/comptabilite/tresorerie/mouvements/m1/recevoir',
        );
        expect(post.mock.calls[0][1]).toEqual({
            compte_tresorerie_destination_id: 'c-agence',
        });
        expect(confirmer.attributes('disabled')).toBeDefined();
        expect(confirmer.attributes('aria-busy')).toBe('true');
        expect(annuler.attributes('disabled')).toBeDefined();
        expect(confirmer.text()).toBe('Confirmation…');
        expect(confirmer.findComponent(Spinner).exists()).toBe(true);

        await terminerRequete();

        expect(confirmer.attributes('disabled')).toBeUndefined();
        expect(annuler.attributes('disabled')).toBeUndefined();
        expect(confirmer.text()).toBe('Confirmer');
        expect(wrapper.findComponent(Spinner).exists()).toBe(false);
    });

    it('n’envoie jamais deux fois, même si un second clic passe avant le rendu', async () => {
        const wrapper = await ouvrirReception();
        const vm = wrapper.vm as unknown as { confirmerReception: () => void };

        vm.confirmerReception();
        vm.confirmerReception();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('garde la fenêtre ouverte pendant l’envoi (Échap, clic à l’extérieur, croix), et laisse la fermer ensuite', async () => {
        const wrapper = await ouvrirReception();
        const fenetre = wrapper.findAllComponents(Dialog)[1];
        expect(fenetre.props('open')).toBe(true);

        await wrapper
            .get('[data-testid="reception-confirmer"]')
            .trigger('click');
        fenetre.vm.$emit('update:open', false);
        await nextTick();

        expect(fenetre.props('open')).toBe(true);

        await terminerRequete();
        fenetre.vm.$emit('update:open', false);
        await nextTick();

        expect(fenetre.props('open')).toBe(false);
    });

    it('protège aussi la fenêtre de motif (annuler / contester / retour) avec le même chargement', async () => {
        const wrapper = monter([
            mouvement({
                statut: 'brouillon',
                statut_label: 'Brouillon',
                peut_annuler: true,
            }),
        ]);
        await bouton(wrapper, 'Annuler').trigger('click');
        await wrapper.get('#motif').setValue('Erreur de saisie');
        const confirmer = wrapper.get('[data-testid="motif-confirmer"]');

        await confirmer.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(
            '/backoffice/comptabilite/tresorerie/mouvements/m1/annuler',
        );
        expect(post.mock.calls[0][1]).toEqual({ motif: 'Erreur de saisie' });
        expect(confirmer.attributes('disabled')).toBeDefined();
        expect(confirmer.text()).toBe('Confirmation…');
        expect(
            wrapper.get('[data-testid="motif-annuler"]').attributes('disabled'),
        ).toBeDefined();

        await terminerRequete();

        expect(confirmer.attributes('disabled')).toBeUndefined();
        expect(confirmer.text()).toBe('Confirmer');
    });

    it('ne verrouille rien quand la saisie est refusée avant l’envoi (motif vide)', async () => {
        const wrapper = monter([
            mouvement({
                statut: 'brouillon',
                statut_label: 'Brouillon',
                peut_annuler: true,
            }),
        ]);
        await bouton(wrapper, 'Annuler').trigger('click');
        const confirmer = wrapper.get('[data-testid="motif-confirmer"]');

        await confirmer.trigger('click');

        expect(post).not.toHaveBeenCalled();
        expect(confirmer.attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('Le motif est obligatoire.');
    });

    it('empêche aussi le double clic sur « Envoyer » dans la liste', async () => {
        const wrapper = monter([
            mouvement({
                statut: 'brouillon',
                statut_label: 'Brouillon',
                peut_envoyer: true,
            }),
        ]);
        const envoyer = bouton(wrapper, 'Envoyer');

        await envoyer.trigger('click');
        await envoyer.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(
            '/backoffice/comptabilite/tresorerie/mouvements/m1/envoyer',
        );
        expect(envoyer.attributes('disabled')).toBeDefined();

        await terminerRequete();

        expect(envoyer.attributes('disabled')).toBeUndefined();
    });
});
