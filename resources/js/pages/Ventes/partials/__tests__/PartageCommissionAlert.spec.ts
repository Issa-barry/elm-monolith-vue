import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PartageCommissionAlert from '../PartageCommissionAlert.vue';
import {
    estErreurPartage,
    type PartageCommissionDetails,
} from '../partage-commission';

const categorie = {
    categorie_id: 'bouteille',
    categorie_nom: 'Bouteille d’eau',
    bareme: 800,
    total_configure: 950,
    ecart: -150,
    membres_manquants: [] as string[],
    motif: 'Partage incorrect',
};
const details: PartageCommissionDetails = {
    vehicule_nom: 'Abarry',
    processus_libelle: 'Vente',
    processus_code: 'vente',
    categories: [categorie],
};
const props = {
    details,
    equipeUrl: '/backoffice/vehicules/123?tab=equipe&processus=vente',
    checking: false,
    checkFailed: false,
};

describe('PartageCommissionAlert', () => {
    it('affiche un résumé compact et ouvre l’équipe sans quitter la commande', async () => {
        const wrapper = mount(PartageCommissionAlert, { props });
        expect(wrapper.get('h3').text()).toBe(
            'Partage de commission à corriger',
        );
        expect(wrapper.text()).toContain(
            'La commande ne peut pas être enregistrée avec ce partage.',
        );
        expect(wrapper.text()).toContain('Abarry · Vente');
        expect(wrapper.text()).not.toContain('GNF');
        expect(wrapper.text()).not.toContain('Bouteille');
        expect(wrapper.text()).not.toContain('Corrigez la répartition');
        expect(wrapper.get('a').attributes()).toMatchObject({
            href: props.equipeUrl,
            target: '_blank',
            rel: 'noopener noreferrer',
        });
        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('masque le lien quand la fiche équipe est inaccessible', () => {
        const wrapper = mount(PartageCommissionAlert, {
            props: { ...props, equipeUrl: null },
        });
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('garde le résumé sans détails et signale un échec de vérification', () => {
        const wrapper = mount(PartageCommissionAlert, {
            props: {
                ...props,
                details: null,
                checkFailed: true,
                checking: true,
            },
        });
        expect(wrapper.text()).toContain(
            'La commande ne peut pas être enregistrée avec ce partage.',
        );
        expect(wrapper.text()).toContain('Vérification indisponible');
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    });

    it('ne confond pas les autres erreurs du véhicule avec une erreur de partage', () => {
        expect(
            estErreurPartage(
                "Impossible de créer ou modifier cette commande : le partage de commission du véhicule Abarry n'est pas conforme",
            ),
        ).toBe(true);
        expect(estErreurPartage('Le véhicule est obligatoire.')).toBe(false);
        expect(estErreurPartage(null)).toBe(false);
    });
});
