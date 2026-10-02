import { describe, expect, it } from 'vitest';
import {
    construireOptions,
    type MoyenEncaissement,
} from '../moyensEncaissement';

const orange: MoyenEncaissement = {
    key: 'mobile_money:s1',
    label: 'Orange Money',
    mode_paiement: 'mobile_money',
    operateur_mobile_money: 'orange_money',
    compte_tresorerie_id: 's1',
    reference_requise: true,
};

const chequeUba: MoyenEncaissement = {
    key: 'cheque:s2',
    label: 'Chèque — UBA',
    mode_paiement: 'cheque',
    operateur_mobile_money: null,
    compte_tresorerie_id: 's2',
    reference_requise: false,
};

describe('construireOptions', () => {
    it("ne propose que les espèces quand l'agence n'a aucun support", () => {
        const options = construireOptions([]);

        expect(options.map((o) => o.key)).toEqual(['especes']);
        expect(options[0].requiresCaisse).toBe(true);
        expect(options[0].compte_tresorerie_id).toBeUndefined();
    });

    it('ajoute exactement les moyens reçus, dans leur ordre, sans opérateur inventé', () => {
        const options = construireOptions([orange, chequeUba]);

        expect(options.map((o) => o.label)).toEqual([
            'Espèces',
            'Orange Money',
            'Chèque — UBA',
        ]);
        expect(options.some((o) => o.label === 'Kulu')).toBe(false);
    });

    it('transmet le support choisi et la règle de référence du backend', () => {
        const [, om, cheque] = construireOptions([orange, chequeUba]);

        expect(om.compte_tresorerie_id).toBe('s1');
        expect(om.mode_paiement).toBe('mobile_money');
        expect(om.requiresReference).toBe(true);
        expect(om.referencePlaceholder).toBe('Ex. OM123456789');
        expect(cheque.compte_tresorerie_id).toBe('s2');
        expect(cheque.requiresReference).toBe(false);
    });
});
