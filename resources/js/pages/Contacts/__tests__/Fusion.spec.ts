import Fusion from '@/pages/Contacts/Fusion.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Vrai useForm d'Inertia : seule la requête est interceptée, pour vérifier ce qui part au serveur.
let envoi: { url: string; donnees: Record<string, unknown> } | null = null;
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const reel = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...reel,
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        router: { get: vi.fn() },
        useForm: (donnees: Record<string, unknown>) => {
            const form = reel.useForm(donnees as Record<string, string>);
            form.post = (url: string) => {
                envoi = { url, donnees: form.data() };
            };

            return form;
        },
    };
});

const simulation = (surcharges: Record<string, unknown> = {}) => ({
    type: 'personne',
    a: {
        id: 'A1',
        nom: 'Rayanatou Sylla',
        lien: '/a',
        telephone: '+224624000001',
    },
    b: { id: 'B1', nom: 'Rayanatou', lien: '/b', telephone: '+224624000002' },
    champs: [
        {
            cle: 'ville',
            libelle: 'Ville',
            valeur_a: null,
            valeur_b: 'Dubreka',
            different: true,
            choix: 'b',
        },
    ],
    telephones: {
        numeros: [
            {
                normalise: '224624000001',
                valeur: '+224624000001',
                origine: 'A — principal',
            },
            {
                normalise: '224624000002',
                valeur: '+224624000002',
                origine: 'B — principal',
            },
        ],
        principal: '224624000001',
        secondaire: '224624000002',
        retires: [],
    },
    interlocuteur_principal: null,
    relations: [],
    compte: null,
    blocages: [],
    confirmations_requises: ['fusion'],
    mot_transfert_compte: 'TRANSFERER',
    choix: {
        ville: 'b',
        telephone: '224624000001',
        telephone_secondaire: '224624000002',
    },
    empreinte: 'e'.repeat(64),
    ...surcharges,
});

const monter = (sim: ReturnType<typeof simulation> | null) =>
    mount(Fusion, {
        props: {
            type: 'personne',
            fiche_a: {
                id: 'A1',
                nom: 'Rayanatou Sylla',
                lien: '/a',
                telephone: null,
            },
            recherche: '',
            candidats: [],
            // Données de test volontairement partielles : forme vérifiée par le serveur.
            simulation: sim as never,
            erreur: null,
            jeton: '11111111-1111-4111-8111-111111111111',
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                DetailHeader: {
                    template:
                        '<div><slot name="subtitle" /><slot name="actions" /></div>',
                },
                Dialog: {
                    props: ['visible'],
                    template:
                        '<div v-if="visible"><slot /><slot name="footer" /></div>',
                },
                Checkbox: {
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template:
                        '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
                },
                RadioButton: { template: '<input type="radio" />' },
                InputText: {
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template:
                        '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
                },
            },
        },
    });

describe('Fusion.vue', () => {
    beforeEach(() => {
        envoi = null;
    });

    it('n’autorise la fusion qu’après la confirmation explicite, puis envoie empreinte, choix et jeton', async () => {
        const vue = monter(simulation());
        const bouton = vue.get('[data-testid="fusion-fusionner"]');
        expect(bouton.attributes('disabled')).toBeDefined();

        await vue.get('[data-testid="fusion-confirmer"]').setValue(true);
        expect(
            vue.get('[data-testid="fusion-fusionner"]').attributes('disabled'),
        ).toBeUndefined();

        await vue.get('[data-testid="fusion-fusionner"]').trigger('click');
        await vue
            .get('[data-testid="fusion-confirmer-dialogue"]')
            .trigger('click');

        expect(envoi?.url).toBe('/backoffice/contacts/fusions');
        expect(envoi?.donnees).toMatchObject({
            type: 'personne',
            fiche_a: 'A1',
            fiche_b: 'B1',
            choix: {
                ville: 'b',
                telephone: '224624000001',
                telephone_secondaire: '224624000002',
            },
            empreinte: 'e'.repeat(64),
            jeton: '11111111-1111-4111-8111-111111111111',
            confirmations: { fusion: true },
        });
    });

    it('n’affiche aucune confirmation quand la simulation est bloquée', () => {
        const vue = monter(
            simulation({
                blocages: [{ code: 'F1', message: 'Même fonction.' }],
            }),
        );

        expect(vue.get('[data-testid="fusion-blocages"]').text()).toContain(
            'Même fonction.',
        );
        expect(vue.find('[data-testid="fusion-fusionner"]').exists()).toBe(
            false,
        );
    });

    it('exige le mot de confirmation pour transférer un compte', async () => {
        const vue = monter(
            simulation({
                confirmations_requises: [
                    'fusion',
                    'compte',
                    'transfert_compte',
                ],
                compte: {
                    cote: 'b',
                    nom: 'Rayanatou',
                    statut: 'active',
                    actif: true,
                    roles: ['Propriétaire'],
                    identifiants_connexion: ['+224624000002'],
                    fonctions_rattachables: [],
                },
            }),
        );
        await vue.get('[data-testid="fusion-confirmer"]').setValue(true);
        await vue.get('[data-testid="fusion-confirmer-compte"]').setValue(true);
        expect(
            vue.get('[data-testid="fusion-fusionner"]').attributes('disabled'),
        ).toBeDefined();

        await vue
            .get('[data-testid="fusion-transfert-compte"]')
            .setValue('transferer');

        expect(
            vue.get('[data-testid="fusion-fusionner"]').attributes('disabled'),
        ).toBeUndefined();
        expect(vue.get('[data-testid="fusion-compte"]').text()).toContain(
            '+224624000002',
        );
    });
});
