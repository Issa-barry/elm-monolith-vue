import VentesParProcessusWidget from '@/components/dashboard/ventes/VentesParProcessusWidget.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const donnees = [
    { code: 'vente', label: 'Vente', montant: 180_000, nb_factures: 3 },
    {
        code: 'distribution_client',
        label: 'Distribution client',
        montant: 200_000,
        nb_factures: 1,
    },
    {
        code: 'transfert_grossiste',
        label: 'Transfert grossiste',
        montant: 400_000,
        nb_factures: 1,
    },
];

describe('VentesParProcessusWidget', () => {
    it('classe les processus du plus gros au plus petit avec montant et part du total', () => {
        const wrapper = mount(VentesParProcessusWidget, {
            props: { caParProcessus: donnees },
        });

        const texte = (el: { text(): string }) =>
            el.text().replace(/\s+/g, ' ');
        const lignes = wrapper.findAll('li');
        expect(lignes.map((l) => l.find('span').text())).toEqual([
            'Transfert grossiste',
            'Distribution client',
            'Vente',
        ]);
        expect(texte(lignes[0])).toContain('400 000 GNF');
        expect(texte(lignes[0])).toContain('51 %');
        expect(texte(lignes[2])).toContain('3 factures');
        expect(texte(wrapper)).toContain('780 000 GNF');
    });

    it('dimensionne les barres par rapport au processus le plus important', () => {
        const wrapper = mount(VentesParProcessusWidget, {
            props: { caParProcessus: donnees },
        });

        const barres = wrapper
            .findAll('li [style]')
            .map((b) => (b.element as HTMLElement).style.width);
        expect(barres).toEqual(['100%', '50%', '45%']);
    });

    it('affiche un état vide quand aucune vente n’est facturée sur la période', () => {
        const wrapper = mount(VentesParProcessusWidget, {
            props: {
                caParProcessus: donnees.map((d) => ({
                    ...d,
                    montant: 0,
                    nb_factures: 0,
                })),
            },
        });

        expect(wrapper.findAll('li')).toHaveLength(0);
        expect(wrapper.text()).toContain('Aucune vente sur la période');
    });
});
