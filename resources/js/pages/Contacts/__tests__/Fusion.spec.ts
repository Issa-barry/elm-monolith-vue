import Fusion from '@/pages/Contacts/Fusion.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Vrai useForm d'Inertia : seule la requête est interceptée, pour vérifier ce qui part au serveur.
let envoi: { url: string; donnees: Record<string, unknown> } | null = null;
let envois = 0;
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
                envois++;
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
    nom_final: 'Chauffeur-7',
    champs: [
        {
            cle: 'nom',
            libelle: 'Nom',
            valeur_a: null,
            valeur_b: 'SYLLA',
            different: true,
            choix: 'a',
        },
        {
            cle: 'pays',
            libelle: 'Pays',
            valeur_a: 'Guinée (GN)',
            valeur_b: 'Guinée (GN)',
            different: false,
            choix: 'a',
        },
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
                fiche: 'a',
                rang: 'principal',
            },
            {
                normalise: '224624000002',
                valeur: '+224624000002',
                origine: 'B — principal',
                fiche: 'b',
                rang: 'principal',
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
        nom: 'a',
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
        envois = 0;
    });

    const ouvrir = async (vue: ReturnType<typeof monter>) => {
        await vue.get('[data-testid="fusion-fusionner"]').trigger('click');
    };

    it('ouvre le récapitulatif, n’envoie qu’après la case cochée, avec empreinte, choix et jeton', async () => {
        const vue = monter(simulation());
        expect(vue.find('[data-testid="fusion-recapitulatif"]').exists()).toBe(
            false,
        );

        await ouvrir(vue);
        const confirmer = () =>
            vue.get('[data-testid="fusion-confirmer-dialogue"]');
        expect(confirmer().attributes('disabled')).toBeDefined();

        await vue.get('[data-testid="fusion-confirmer"]').setValue(true);
        expect(confirmer().attributes('disabled')).toBeUndefined();
        await confirmer().trigger('click');

        expect(envoi?.url).toBe('/backoffice/contacts/fusions');
        expect(envoi?.donnees).toMatchObject({
            type: 'personne',
            fiche_a: 'A1',
            fiche_b: 'B1',
            choix: {
                nom: 'a',
                ville: 'b',
                telephone: '224624000001',
                telephone_secondaire: '224624000002',
            },
            empreinte: 'e'.repeat(64),
            jeton: '11111111-1111-4111-8111-111111111111',
            confirmations: { fusion: true },
        });
    });

    it('récapitule la fiche finale, ce qui est repris et ce qui ne l’est pas, d’après la simulation', async () => {
        const vue = monter(simulation());
        await ouvrir(vue);

        const fiche = vue.get('[data-testid="fusion-recap-fiche"]').text();
        expect(fiche).toContain('Chauffeur-7');
        expect(fiche).toContain('Dubreka');
        expect(fiche).toContain('Guinée (GN)');

        const repris = vue.get('[data-testid="fusion-recap-repris"]').text();
        expect(repris).toContain('Ville : Dubreka');

        // Nom de B écarté alors que la fiche conservée n'en a pas : signalé, jamais silencieux.
        const nonRepris = vue
            .get('[data-testid="fusion-recap-non-repris"]')
            .text();
        expect(nonRepris).toContain('SYLLA');
        expect(nonRepris).toContain('le champ restera vide');
        expect(nonRepris).not.toContain('Dubreka');
    });

    it('affiche « Non renseigné » plutôt qu’un tiret et signale le champ qui restera vide', () => {
        const vue = monter(simulation());

        expect(vue.get('[data-testid="fusion-champ-nom-a"]').text()).toContain(
            'Non renseigné',
        );
        expect(vue.get('[data-testid="fusion-simulation"]').text()).toContain(
            'Ce champ restera vide',
        );
        expect(vue.get('[data-testid="fusion-nom-final"]').text()).toBe(
            'Chauffeur-7',
        );
    });

    it('n’envoie qu’une fois sur un double clic', async () => {
        const vue = monter(simulation());
        await ouvrir(vue);
        await vue.get('[data-testid="fusion-confirmer"]').setValue(true);

        const vm = vue.vm as unknown as { fusionner: () => void };
        vm.fusionner();
        vm.fusionner();

        expect(envois).toBe(1);
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
        expect(vue.get('[data-testid="fusion-compte"]').text()).toContain(
            '+224624000002',
        );

        await ouvrir(vue);
        await vue.get('[data-testid="fusion-confirmer"]').setValue(true);
        await vue.get('[data-testid="fusion-confirmer-compte"]').setValue(true);
        const confirmer = () =>
            vue.get('[data-testid="fusion-confirmer-dialogue"]');
        expect(confirmer().attributes('disabled')).toBeDefined();

        await vue
            .get('[data-testid="fusion-transfert-compte"]')
            .setValue('transferer');

        expect(confirmer().attributes('disabled')).toBeUndefined();
    });
});
