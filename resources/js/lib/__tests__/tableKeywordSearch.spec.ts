import { filtrerParMotCle } from '@/lib/tableKeywordSearch';
import { describe, expect, it } from 'vitest';

interface Ligne {
    nom: string;
    telephone: string | null;
    vehicule: string | null;
    montant: number;
}

const lignes: Ligne[] = [
    {
        nom: 'Camara Ya Moussa',
        telephone: '+224629331246',
        vehicule: 'Abdoulaye',
        montant: 642000,
    },
    {
        nom: 'Saa Fodé',
        telephone: '+224613855281',
        vehicule: 'Abarry',
        montant: 1656000,
    },
    {
        nom: 'Chauffeur-1 Thierno-Moto',
        telephone: null,
        vehicule: null,
        montant: 190000,
    },
];

const valeurs = (l: Ligne) => [l.nom, l.telephone, l.vehicule, l.montant];

function noms(motCle: string): string[] {
    return filtrerParMotCle(lignes, motCle, valeurs).map((l) => l.nom);
}

describe('filtrerParMotCle', () => {
    it('renvoie toutes les lignes sans mot-clé', () => {
        expect(noms('')).toHaveLength(3);
        expect(noms('   ')).toHaveLength(3);
    });

    it('cherche dans toutes les colonnes fournies, sans tenir compte de la casse', () => {
        expect(noms('ABARRY')).toEqual(['Saa Fodé']);
        expect(noms('moussa')).toEqual(['Camara Ya Moussa']);
    });

    it('ignore les accents', () => {
        expect(noms('fode')).toEqual(['Saa Fodé']);
    });

    it('trouve un montant tel qu’affiché, avec séparateurs de milliers', () => {
        expect(noms('642 000')).toEqual(['Camara Ya Moussa']);
        expect(noms('1 656 000')).toEqual(['Saa Fodé']);
    });

    it('trouve un téléphone saisi avec ou sans espaces', () => {
        expect(noms('629 331')).toEqual(['Camara Ya Moussa']);
        expect(noms('+224 613')).toEqual(['Saa Fodé']);
    });

    it('ignore les valeurs vides et ne renvoie rien sans correspondance', () => {
        expect(noms('inconnu')).toEqual([]);
    });
});
