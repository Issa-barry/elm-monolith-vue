import ModifierIdentiteDialog from '@/pages/Contacts/partials/ModifierIdentiteDialog.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Vrai useForm d'Inertia : seule la requête est interceptée, pour vérifier ce qui part au serveur.
let envoi: { url: string; donnees: Record<string, unknown> } | null = null;
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const reel = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...reel,
        useForm: (donnees: Record<string, string>) => {
            const form = reel.useForm(donnees);
            form.patch = (url: string) => {
                envoi = { url, donnees: form.data() };
            };

            return form;
        },
    };
});

const monter = (
    type: 'personne' | 'entreprise',
    valeurs: Record<string, string | null>,
) =>
    mount(ModifierIdentiteDialog, {
        props: { visible: true, type, url: '/fiche', valeurs },
        global: {
            stubs: {
                Dialog: {
                    template: '<div><slot /><slot name="footer" /></div>',
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

describe('Contacts — modifier l’identité d’une fiche', () => {
    beforeEach(() => {
        envoi = null;
    });

    it('envoie toutes les valeurs de la fiche Personne, pas un formulaire vide', async () => {
        const wrapper = monter('personne', {
            prenom: 'Rayanatou',
            nom: 'SYLLA',
            nom_complet: null,
            surnom: 'RAYA',
            telephone: '+224624866799',
            email: 'raya01.elm@gmail.com',
            ville: 'Conakry',
            adresse: 'Matoto',
        });

        await wrapper
            .find('[data-testid="identite-adresse"]')
            .setValue('Dubreka');
        await wrapper
            .find('[data-testid="identite-enregistrer"]')
            .trigger('click');

        expect(envoi?.url).toBe('/fiche');
        expect(envoi?.donnees).toMatchObject({
            prenom: 'Rayanatou',
            nom: 'SYLLA',
            nom_complet: '',
            surnom: 'RAYA',
            telephone: '+224624866799',
            telephone_secondaire: '',
            email: 'raya01.elm@gmail.com',
            ville: 'Conakry',
            quartier: '',
            adresse: 'Dubreka',
        });
    });

    it('envoie toutes les valeurs de la fiche Entreprise', async () => {
        const wrapper = monter('entreprise', {
            raison_sociale: 'Kaloum Services',
            identifiant_fiscal: 'GN12345',
            ville: 'Conakry',
        });

        await wrapper
            .find('[data-testid="identite-enregistrer"]')
            .trigger('click');

        expect(envoi?.donnees).toMatchObject({
            raison_sociale: 'Kaloum Services',
            identifiant_fiscal: 'GN12345',
            ville: 'Conakry',
            nom_commercial: '',
        });
    });
});
