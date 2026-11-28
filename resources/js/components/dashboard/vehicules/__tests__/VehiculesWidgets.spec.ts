import VehiculesAConfigurerWidget from '@/components/dashboard/vehicules/VehiculesAConfigurerWidget.vue';
import VehiculesCartesWidget from '@/components/dashboard/vehicules/VehiculesCartesWidget.vue';
import VehiculesCategoriesWidget from '@/components/dashboard/vehicules/VehiculesCategoriesWidget.vue';
import VehiculesClassementWidget from '@/components/dashboard/vehicules/VehiculesClassementWidget.vue';
import VehiculesOperationsWidget from '@/components/dashboard/vehicules/VehiculesOperationsWidget.vue';
import {
    nombreTexte,
    ouTiret,
    pluriel,
} from '@/components/dashboard/vehicules/nombres';
import type {
    ActiviteVehicules,
    CategorieVehicules,
    OperationVehicules,
    ParcVehicules,
    VehiculeClasse,
} from '@/types/dashboard-vehicules';
import { mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { href: props.href, ...attrs }, slots.default?.()),
        }),
    };
});

const texte = (s: string) => s.replace(/\s+/g, ' ').trim();
const cellules = (ligne: ReturnType<ReturnType<typeof mount>['find']>) =>
    ligne.findAll('td').map((td) => texte(td.text()));
const global = { plugins: [PrimeVue] };

const TOUS = { ventes: true, transferts: true, depenses: true };

const parc = (champs: Partial<ParcVehicules> = {}): ParcVehicules => ({
    total: 10,
    actifs: 8,
    inactifs: 2,
    operationnels: 6,
    a_configurer: 2,
    en_mission: { vehicules: 3, ventes: 2, transferts: 1 },
    ...champs,
});

const operation = (
    code: OperationVehicules['code'],
    champs: Partial<OperationVehicules> = {},
): OperationVehicules => ({
    code,
    libelle: {
        vente: 'Vente standard',
        distribution: 'Distribution client',
        grossiste: 'Livraison grossiste',
        transfert: 'Transfert',
    }[code],
    usage: {
        vente: 'Vente',
        distribution: 'Logistique',
        grossiste: 'Grossiste',
        transfert: 'Logistique',
    }[code],
    vehicules: 4,
    possibles: 3,
    bloques: 1,
    rotations: 10,
    vehicules_utilises: 2,
    jours_vehicule: 7,
    ...champs,
});

const OPERATIONS = [
    operation('vente', { rotations: 12 }),
    operation('distribution', { rotations: 3 }),
    operation('grossiste', { rotations: 1 }),
    operation('transfert', { rotations: 4 }),
];

const activite = (
    champs: Partial<ActiviteVehicules> = {},
): ActiviteVehicules => ({
    rotations: 20,
    vehicules_utilises: 5,
    actifs: 8,
    actifs_utilises: 4,
    taux_utilisation: 50,
    jours_vehicule: 21,
    avec_retour: 2,
    taux_retour: 10,
    transferts_avec_ecart: 1,
    ...champs,
});

const carte = (wrapper: ReturnType<typeof mount>, id: string) => {
    const el = wrapper.find(`[data-testid="kpi-${id}"]`);
    return {
        existe: el.exists(),
        valeur: el.exists()
            ? texte(el.find('[data-slot="valeur"]').text())
            : '',
        detail: el.exists()
            ? texte(el.find('[data-slot="detail"]').text())
            : '',
    };
};

describe('nombres', () => {
    it('formate des entiers français, accorde le pluriel et remplace une valeur absente par un tiret', () => {
        expect(nombreTexte(12500)).toBe('12 500');
        expect(pluriel(1, 'véhicule')).toBe('1 véhicule');
        expect(pluriel(0, 'véhicule')).toBe('0 véhicule');
        expect(pluriel(3, 'véhicule actif', 'véhicules actifs')).toBe(
            '3 véhicules actifs',
        );
        expect(ouTiret(null)).toBe('—');
        expect(ouTiret(0)).toBe('0');
    });
});

describe('VehiculesCartesWidget', () => {
    const monter = (props: Record<string, unknown> = {}) =>
        mount(VehiculesCartesWidget, {
            props: {
                blocs: TOUS,
                parc: parc(),
                operations: OPERATIONS,
                activite: activite(),
                depenses: {
                    montant: 300000,
                    nombre: 4,
                    vehicules: 2,
                    rotations: 20,
                    cout_par_rotation: 15000,
                },
                ...props,
            },
        });

    it('affiche le parc, l’activité et les dépenses', () => {
        const wrapper = monter();

        expect(carte(wrapper, 'actifs')).toMatchObject({
            valeur: '8',
            detail: '2 inactifs · 10 au total',
        });
        expect(carte(wrapper, 'operationnels').detail).toBe(
            '75,0 % des véhicules actifs',
        );
        expect(carte(wrapper, 'en-mission')).toMatchObject({
            valeur: '3',
            detail: '2 en vente · 1 en transfert',
        });
        expect(carte(wrapper, 'rotations')).toMatchObject({
            valeur: '20',
            detail: '16 ventes · 4 transferts',
        });
        expect(carte(wrapper, 'utilisation')).toMatchObject({
            valeur: '50,0 %',
            detail: '4 sur 8 véhicules actifs',
        });
        expect(carte(wrapper, 'jours').detail).toBe(
            '4,2 jours par véhicule sorti',
        );
        expect(carte(wrapper, 'retours').detail).toBe(
            '10,0 % des rotations · 1 écart de réception',
        );
        expect(carte(wrapper, 'cout-rotation').detail).toBe(
            '15 000 GNF · 20 rotations',
        );
    });

    it('signale en orange les véhicules à configurer', () => {
        const detail = monter()
            .find('[data-testid="kpi-a-configurer"] [data-slot="detail"]')
            .classes();
        expect(detail).toContain('text-amber-600');
    });

    it('n’affiche que les blocs envoyés par le serveur', () => {
        const wrapper = monter({
            blocs: { ventes: false, transferts: false, depenses: false },
            parc: parc({ en_mission: null }),
            activite: null,
            depenses: null,
        });

        expect(carte(wrapper, 'actifs').existe).toBe(true);
        expect(carte(wrapper, 'en-mission').existe).toBe(false);
        expect(carte(wrapper, 'rotations').existe).toBe(false);
        expect(carte(wrapper, 'depenses-validees').existe).toBe(false);
        expect(
            wrapper.find('[data-testid="vehicules-ligne-activite"]').exists(),
        ).toBe(false);
    });

    it('précise la source manquante plutôt que d’afficher un zéro', () => {
        const wrapper = monter({
            blocs: { ventes: true, transferts: false, depenses: true },
            parc: parc({
                en_mission: { vehicules: 2, ventes: 2, transferts: null },
            }),
            activite: activite({ transferts_avec_ecart: null }),
        });

        expect(carte(wrapper, 'en-mission').detail).toBe('2 en vente');
        expect(carte(wrapper, 'rotations').detail).toBe(
            '16 ventes · transferts non inclus',
        );
        expect(carte(wrapper, 'retours').detail).toBe('10,0 % des rotations');
        expect(
            texte(
                wrapper.find('[data-testid="vehicules-ligne-activite"]').text(),
            ),
        ).toContain('ventes seulement');
    });

    it('affiche un tiret sans coût par rotation calculable', () => {
        const wrapper = monter({
            activite: null,
            depenses: {
                montant: 300000,
                nombre: 4,
                vehicules: 2,
                rotations: null,
                cout_par_rotation: null,
            },
        });

        expect(carte(wrapper, 'cout-rotation')).toMatchObject({
            valeur: '—',
            detail: 'Activité non consultable',
        });
    });
});

describe('VehiculesOperationsWidget', () => {
    it('affiche une ligne par opération et un tiret pour une source non consultable', () => {
        const wrapper = mount(VehiculesOperationsWidget, {
            props: {
                blocs: { ventes: true, transferts: false, depenses: false },
                operations: [
                    operation('vente'),
                    operation('transfert', {
                        rotations: null,
                        vehicules_utilises: null,
                        jours_vehicule: null,
                    }),
                ],
            },
            global,
        });
        const lignes = wrapper.findAll(
            '[data-testid="vehicules-operations"] tbody tr',
        );

        expect(cellules(lignes[0])).toEqual([
            'Vente standard· usage Vente',
            '4',
            '3',
            '1',
            '10',
            '2',
            '7',
        ]);
        expect(cellules(lignes[1]).slice(4)).toEqual(['—', '—', '—']);
    });

    it('explique une activité grossiste nulle au lieu d’un zéro muet', () => {
        const sansVehicule = mount(VehiculesOperationsWidget, {
            props: {
                blocs: TOUS,
                operations: [operation('grossiste', { vehicules: 0 })],
            },
            global,
        });
        expect(
            sansVehicule
                .find('[data-testid="vehicules-note-grossiste"]')
                .text(),
        ).toContain('Aucun véhicule actif du parc n’a l’usage Grossiste');

        const sansRotation = mount(VehiculesOperationsWidget, {
            props: {
                blocs: TOUS,
                operations: [operation('grossiste', { rotations: 0 })],
            },
            global,
        });
        expect(
            sansRotation
                .find('[data-testid="vehicules-note-grossiste"]')
                .text(),
        ).toContain('Aucune livraison grossiste sur la période');
    });
});

describe('VehiculesCategoriesWidget', () => {
    it('présente chaque catégorie séparément, sans total', () => {
        const ligne = (
            champs: Partial<CategorieVehicules>,
        ): CategorieVehicules => ({
            categorie_id: 'c',
            categorie: 'Sachet eau',
            capacite_parc: 2700,
            vehicules_plafonnes: 5,
            charge: 280,
            chargements: 3,
            remplissage: 90,
            retourne: 5,
            taux_retour: (5 / 280) * 100,
            ecarts_manquants: 0,
            ecarts_surplus: 10,
            ...champs,
        });
        const wrapper = mount(VehiculesCategoriesWidget, {
            props: {
                lignes: [
                    ligne({}),
                    ligne({
                        categorie_id: 'b',
                        categorie: 'Bouteille',
                        charge: null,
                        remplissage: null,
                        retourne: null,
                        taux_retour: null,
                        ecarts_manquants: null,
                        ecarts_surplus: null,
                    }),
                    ligne({
                        categorie_id: 'p',
                        categorie: 'Pack',
                        ecarts_manquants: 0,
                        ecarts_surplus: 0,
                    }),
                ],
            },
            global,
        });
        const lignes = wrapper.findAll(
            '[data-testid="vehicules-categories"] tbody tr',
        );

        expect(lignes).toHaveLength(3);
        expect(cellules(lignes[0])).toEqual([
            'Sachet eau',
            '2 700 · 5 véhicules',
            '280',
            '90,0 %',
            '5 · 1,8 %',
            '−0 / +10',
        ]);
        expect(cellules(lignes[1]).slice(2)).toEqual(['—', '—', '—', '—']);
        // Aucun écart : « 0 », pas « −0 / +0 ».
        expect(cellules(lignes[2]).at(-1)).toBe('0');
        expect(wrapper.text()).not.toContain('Total');
    });
});

describe('VehiculesClassementWidget', () => {
    const vehicule = (
        champs: Partial<VehiculeClasse> = {},
    ): VehiculeClasse => ({
        id: 'v1',
        nom: 'Tricycle 1',
        immatriculation: 'AB-123',
        site: 'Matoto',
        type: 'Tricycle',
        usages: 'Vente',
        statut: 'operationnel',
        statut_label: 'Opérationnel',
        supprime: false,
        rotations: 4,
        jours: 3,
        avec_retour: 1,
        depenses: 25000,
        ...champs,
    });

    it('relie chaque véhicule à sa fiche et affiche son état en point coloré', () => {
        const wrapper = mount(VehiculesClassementWidget, {
            props: {
                lignes: [
                    vehicule(),
                    vehicule({ id: 'v2', nom: 'Ancien', supprime: true }),
                ],
                activite: true,
                depenses: true,
            },
            global,
        });

        const liens = wrapper.findAll('[data-testid="vehicule-fiche"]');
        expect(liens).toHaveLength(1);
        expect(liens[0].attributes('href')).toBe('/backoffice/vehicules/v1');
        const premiere = cellules(
            wrapper.findAll('[data-testid="vehicules-classement"] tbody tr')[0],
        );
        expect(premiere).toEqual([
            'Tricycle 1· AB-123',
            'Matoto',
            'Tricycle',
            'Opérationnel',
            '4',
            '3',
            '1',
            '25 000 GNF',
        ]);
        expect(wrapper.find('.bg-emerald-500').exists()).toBe(true);
    });

    it('n’affiche pas les colonnes d’une source non consultable', () => {
        const wrapper = mount(VehiculesClassementWidget, {
            props: {
                lignes: [
                    vehicule({
                        rotations: null,
                        jours: null,
                        avec_retour: null,
                        depenses: null,
                    }),
                ],
                activite: false,
                depenses: false,
            },
            global,
        });
        const entetes = wrapper
            .findAll('[data-testid="vehicules-classement"] th')
            .map((th) => texte(th.text()));

        expect(entetes).toEqual([
            'Véhicule',
            'Agence actuelle',
            'Type',
            'État',
        ]);
    });
});

describe('VehiculesAConfigurerWidget', () => {
    it('liste les motifs du serveur en orange', () => {
        const wrapper = mount(VehiculesAConfigurerWidget, {
            props: {
                lignes: [
                    {
                        id: 'v1',
                        nom: 'Camion 1',
                        immatriculation: null,
                        site: 'Matoto',
                        usages: 'Logistique',
                        motifs: ['Distribution impossible : aucune équipe.'],
                    },
                ],
            },
            global,
        });

        const motifs = wrapper.find(
            '[data-testid="vehicules-a-configurer"] ul',
        );
        expect(motifs.text()).toContain('Distribution impossible');
        expect(motifs.classes()).toContain('text-amber-600');
    });

    it('signale qu’aucun véhicule n’est à configurer', () => {
        const wrapper = mount(VehiculesAConfigurerWidget, {
            props: { lignes: [] },
            global,
        });
        expect(wrapper.text()).toContain(
            'Tous les véhicules actifs sont opérationnels',
        );
    });
});
