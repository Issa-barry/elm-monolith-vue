import {
    analyserSaisieMontant,
    formaterMontantSaisie,
} from '@/lib/montantSaisie';
import { describe, expect, it } from 'vitest';

describe('analyserSaisieMontant', () => {
    it('ajoute les séparateurs de milliers et garde une valeur numérique propre', () => {
        expect(analyserSaisieMontant('750000')).toEqual({
            valeur: 750000,
            affichage: '750 000',
        });
        expect(analyserSaisieMontant('1234567')).toEqual({
            valeur: 1234567,
            affichage: '1 234 567',
        });
    });

    it('accepte un montant collé avec des espaces, des espaces insécables ou des points', () => {
        expect(analyserSaisieMontant('750 000').valeur).toBe(750000);
        expect(analyserSaisieMontant('750 000 GNF').valeur).toBe(750000);
        expect(analyserSaisieMontant('750.000').valeur).toBe(750000);
        expect(analyserSaisieMontant('1.234.567').valeur).toBe(1234567);
    });

    it('rend null pour un champ vide ou sans chiffre', () => {
        expect(analyserSaisieMontant('')).toEqual({
            valeur: null,
            affichage: '',
        });
        expect(analyserSaisieMontant('abc')).toEqual({
            valeur: null,
            affichage: '',
        });
    });

    it('ignore la partie décimale quand le champ ne l’autorise pas', () => {
        expect(analyserSaisieMontant('750000,50')).toEqual({
            valeur: 750000,
            affichage: '750 000',
        });
    });

    it('gère les décimales quand le champ les autorise', () => {
        expect(analyserSaisieMontant('1250,5', 2)).toEqual({
            valeur: 1250.5,
            affichage: '1 250,5',
        });
        expect(analyserSaisieMontant('1250.75', 2).valeur).toBe(1250.75);
        expect(analyserSaisieMontant('1250,123', 2).valeur).toBe(1250123);
        // Virgule en cours de frappe : conservée à l'affichage.
        expect(analyserSaisieMontant('1250,', 2)).toEqual({
            valeur: 1250,
            affichage: '1 250,',
        });
        expect(analyserSaisieMontant('0,5', 2).valeur).toBe(0.5);
    });

    it('retire les zéros de tête', () => {
        expect(analyserSaisieMontant('000750').affichage).toBe('750');
        expect(analyserSaisieMontant('0').valeur).toBe(0);
    });
});

describe('formaterMontantSaisie', () => {
    it('formate un nombre ou une chaîne décimale du serveur', () => {
        expect(formaterMontantSaisie(750000)).toBe('750 000');
        expect(formaterMontantSaisie('2500000.00')).toBe('2 500 000');
        expect(formaterMontantSaisie(0)).toBe('0');
    });

    it('laisse vide une valeur absente', () => {
        expect(formaterMontantSaisie(null)).toBe('');
        expect(formaterMontantSaisie(undefined)).toBe('');
        expect(formaterMontantSaisie('')).toBe('');
    });

    it('ne masque jamais une valeur décimale existante', () => {
        expect(formaterMontantSaisie('233333.33')).toBe('233 333,33');
        expect(formaterMontantSaisie(1250.5, 2)).toBe('1 250,5');
    });
});
